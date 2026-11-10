<?php

declare(strict_types=1);

namespace App\Tests\Network;

use App\Network\AllowedHosts;
use App\Network\ListenAddress;
use App\Network\NetworkProxy;
use App\Network\ProxyServer;
use App\Network\Streams;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The proxy over real sockets on this machine: a command's side, the proxy, and an upstream server standing in for
 * the registry, all in this process. Every host resolves to a public address the dialer turns into the upstream's.
 */
final class NetworkProxyTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../var/test-network-proxy';

    /** @var resource */
    private $upstream;

    private RecordedDecisions $decisions;

    private ProxyServer $proxy;

    #[\Override]
    protected function setUp(): void
    {
        $this->upstream = Streams::server('tcp://127.0.0.1:0');
        $this->decisions = new RecordedDecisions();
        $this->proxy = $this->listen(ListenAddress::fromOption('127.0.0.1:0'));
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->proxy->close();
        fclose($this->upstream);
    }

    public function testAConnectToARegistryIsTunnelledBothWays(): void
    {
        $command = $this->connect();
        fwrite($command, "CONNECT registry.npmjs.org:443 HTTP/1.1\r\nHost: registry.npmjs.org:443\r\n\r\n");

        self::assertSame("HTTP/1.1 200 Connection established\r\n\r\n", $this->receive($command));
        $registry = $this->acceptUpstream();
        fwrite($command, 'client hello');
        self::assertSame('client hello', $this->receive($registry));
        fwrite($registry, 'server hello');
        self::assertSame('server hello', $this->receive($command));
        self::assertSame(['registry.npmjs.org:443 allowed'], $this->decisions->lines);
    }

    public function testBytesSentWithTheConnectHeadReachTheRegistry(): void
    {
        $command = $this->connect();
        fwrite($command, "CONNECT pypi.org:443 HTTP/1.1\r\n\r\nearly bytes");

        self::assertSame("HTTP/1.1 200 Connection established\r\n\r\n", $this->receive($command));
        self::assertSame('early bytes', $this->receive($this->acceptUpstream()));
    }

    public function testAHostOffTheListGetsAForbiddenAnswerNamingIt(): void
    {
        $command = $this->connect();
        fwrite($command, "CONNECT evil.example:443 HTTP/1.1\r\n\r\n");

        $answer = $this->receiveUntilClosed($command);

        self::assertStringStartsWith("HTTP/1.1 403 Forbidden\r\n", $answer);
        self::assertStringEndsWith("\r\n\r\nAirlock: blocked a connection to evil.example:443, which is not a package registry.\n", $answer);
        self::assertSame(['evil.example:443 not_listed'], $this->decisions->lines);
    }

    public function testAPlainHttpRequestIsForwardedAsItsPath(): void
    {
        $this->proxy->close();
        $this->proxy = $this->listen(ListenAddress::fromOption('127.0.0.1:0'), new AllowedHosts(['mirror.example' => [80]]));
        $command = $this->connect();
        fwrite($command, "GET http://mirror.example/packages.json HTTP/1.1\r\nHost: mirror.example\r\nProxy-Connection: keep-alive\r\n\r\n");

        $this->pump();
        $mirror = $this->acceptUpstream();

        self::assertSame("GET /packages.json HTTP/1.1\r\nHost: mirror.example\r\nConnection: close\r\n\r\n", $this->receive($mirror));
        fwrite($mirror, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\n{}");
        fclose($mirror);
        self::assertSame("HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\n{}", $this->receiveUntilClosed($command));
    }

    public function testAMalformedRequestGetsABadRequestAnswer(): void
    {
        $command = $this->connect();
        fwrite($command, "GET /index.html HTTP/1.1\r\nHost: example.org\r\n\r\n");

        self::assertStringStartsWith("HTTP/1.1 400 Bad Request\r\n", $this->receiveUntilClosed($command));
        self::assertSame([], $this->decisions->lines);
    }

    public function testAHeadTooLongIsRefused(): void
    {
        $command = $this->connect();
        fwrite($command, "CONNECT pypi.org:443 HTTP/1.1\r\nX-Padding: ".str_repeat('a', 20_000));

        self::assertStringStartsWith("HTTP/1.1 431 Request Header Fields Too Large\r\n", $this->receiveUntilClosed($command));
    }

    public function testTheProxyListensOnAUnixSocketAndRemovesItWhenClosed(): void
    {
        new Filesystem()->mkdir(self::ROOT);
        $socket = self::ROOT.'/proxy.sock';
        new Filesystem()->remove($socket);
        $this->proxy->close();
        $this->proxy = $this->listen(ListenAddress::fromOption('unix://'.$socket));

        self::assertSame($socket, $this->proxy->address());
        $command = $this->connect();
        fwrite($command, "CONNECT pypi.org:443 HTTP/1.1\r\n\r\n");
        self::assertSame("HTTP/1.1 200 Connection established\r\n\r\n", $this->receive($command));

        $this->proxy->close();
        self::assertFileDoesNotExist($socket);
        $this->proxy = $this->listen(ListenAddress::fromOption('127.0.0.1:0'));
    }

    private function listen(ListenAddress $address, ?AllowedHosts $allowedHosts = null): ProxyServer
    {
        $resolver = new FixedResolver([
            'registry.npmjs.org' => ['151.101.0.7'],
            'pypi.org' => ['151.101.0.8'],
            'mirror.example' => ['151.101.0.9'],
        ]);
        $dialer = new LocalDialer('tcp://'.stream_socket_get_name($this->upstream, false));

        return new NetworkProxy($resolver, $dialer)->listen($address, $allowedHosts ?? AllowedHosts::registries(), $this->decisions);
    }

    /**
     * @return resource
     */
    private function connect()
    {
        $address = $this->proxy->address();
        $client = Streams::client(str_starts_with($address, '/') ? 'unix://'.$address : 'tcp://'.$address, 1.0);
        $this->pump();

        return $client;
    }

    /**
     * @return resource
     */
    private function acceptUpstream()
    {
        $this->pump();

        return Streams::accept($this->upstream);
    }

    /**
     * Lets the proxy serve what is waiting: it runs in this process, so it serves only when a test lets it.
     */
    private function pump(): void
    {
        for ($tick = 0; $tick < 5; ++$tick) {
            $this->proxy->tick(0.01);
        }
    }

    /**
     * @param resource $stream
     */
    private function receive($stream): string
    {
        $this->pump();
        self::assertNotSame([], Streams::readable([$stream], 1.0), 'Nothing came within a second.');

        return Streams::read($stream, 65536);
    }

    /**
     * @param resource $stream
     */
    private function receiveUntilClosed($stream): string
    {
        $received = '';
        for ($attempt = 0; $attempt < 50; ++$attempt) {
            $this->pump();
            if ([] === Streams::readable([$stream], 0.05)) {
                continue;
            }
            $bytes = Streams::read($stream, 65536);
            if ('' === $bytes) {
                return $received;
            }
            $received .= $bytes;
        }

        self::fail('The connection was not closed. Received: '.$received);
    }
}
