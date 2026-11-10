<?php

declare(strict_types=1);

namespace App\Tests\Network;

use App\Network\MalformedProxyRequestException;
use App\Network\ProxyRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProxyRequestTest extends TestCase
{
    public function testAConnectRequestOpensATunnelToItsHostAndPort(): void
    {
        $request = ProxyRequest::fromHead("CONNECT Repo.Packagist.org.:443 HTTP/1.1\r\nHost: repo.packagist.org:443\r\n\r\n");

        self::assertTrue($request->isTunnel);
        self::assertSame('repo.packagist.org', $request->target->host);
        self::assertSame(443, $request->target->port);
    }

    public function testAConnectRequestToAnIpv6AddressKeepsItsBrackets(): void
    {
        $request = ProxyRequest::fromHead("CONNECT [2001:DB8::1]:443 HTTP/1.1\r\n\r\n");

        self::assertSame('2001:db8::1', $request->target->host);
        self::assertSame('[2001:db8::1]:443', $request->target->authority());
    }

    public function testAnAbsoluteUrlIsForwardedAsItsPathWithTheConnectionClosedAfter(): void
    {
        $request = ProxyRequest::fromHead(
            "GET http://example.org/simple/?q=1 HTTP/1.1\r\nHost: example.org\r\nProxy-Connection: keep-alive\r\n"
            ."Proxy-Authorization: Basic eDp5\r\nConnection: keep-alive\r\nAccept: */*\r\n\r\n",
        );

        self::assertFalse($request->isTunnel);
        self::assertSame('example.org:80', $request->target->authority());
        self::assertSame("GET /simple/?q=1 HTTP/1.1\r\nHost: example.org\r\nAccept: */*\r\nConnection: close\r\n\r\n", $request->headToForward);
    }

    public function testAnAbsoluteUrlWithoutAPathAsksForTheRoot(): void
    {
        $request = ProxyRequest::fromHead("GET http://example.org:8080 HTTP/1.0\r\n\r\n");

        self::assertSame('example.org:8080', $request->target->authority());
        self::assertSame("GET / HTTP/1.0\r\nConnection: close\r\n\r\n", $request->headToForward);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedHeads(): iterable
    {
        yield 'no HTTP version' => ["CONNECT example.org:443\r\n\r\n"];
        yield 'HTTP/2' => ["CONNECT example.org:443 HTTP/2\r\n\r\n"];
        yield 'CONNECT without a port' => ["CONNECT example.org HTTP/1.1\r\n\r\n"];
        yield 'port 0' => ["CONNECT example.org:0 HTTP/1.1\r\n\r\n"];
        yield 'port past 65535' => ["CONNECT example.org:70000 HTTP/1.1\r\n\r\n"];
        yield 'a path alone' => ["GET /index.html HTTP/1.1\r\nHost: example.org\r\n\r\n"];
        yield 'an https URL, which takes CONNECT' => ["GET https://example.org/ HTTP/1.1\r\n\r\n"];
        yield 'credentials in the URL' => ["GET http://user@example.org/ HTTP/1.1\r\n\r\n"];
        yield 'an underscore in the host' => ["CONNECT ex_ample.org:443 HTTP/1.1\r\n\r\n"];
        yield 'an empty label' => ["CONNECT example..org:443 HTTP/1.1\r\n\r\n"];
        yield 'not an IPv6 address' => ["CONNECT [::g]:443 HTTP/1.1\r\n\r\n"];
    }

    #[DataProvider('malformedHeads')]
    public function testAMalformedRequestIsRefused(string $head): void
    {
        $this->expectException(MalformedProxyRequestException::class);

        ProxyRequest::fromHead($head);
    }
}
