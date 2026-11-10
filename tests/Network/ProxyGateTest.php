<?php

declare(strict_types=1);

namespace App\Tests\Network;

use App\Network\AllowedHosts;
use App\Network\ProxyDecision;
use App\Network\ProxyGate;
use App\Network\ProxyRefusedException;
use App\Network\ProxyTarget;
use App\Network\Streams;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProxyGateTest extends TestCase
{
    /** A public address, which the local dialer never really dials. */
    private const string PUBLIC_ADDRESS = '151.101.0.7';

    /** @var resource */
    private $server;

    private RecordedDecisions $decisions;

    #[\Override]
    protected function setUp(): void
    {
        $this->server = Streams::server('tcp://127.0.0.1:0');
        $this->decisions = new RecordedDecisions();
    }

    #[\Override]
    protected function tearDown(): void
    {
        fclose($this->server);
    }

    public function testAListedRegistryIsOpenedAtTheAddressItsNameStandsFor(): void
    {
        $dialer = $this->dialer();
        $gate = $this->gate(['repo.packagist.org' => [self::PUBLIC_ADDRESS]], $dialer);

        $connection = $gate->open($this->target('repo.packagist.org:443'));

        self::assertIsResource($connection);
        self::assertSame([self::PUBLIC_ADDRESS.':443'], $dialer->dialed);
        self::assertSame(['repo.packagist.org:443 allowed'], $this->decisions->lines);
    }

    public function testAHostOffTheListIsRefusedWithoutBeingResolved(): void
    {
        $dialer = $this->dialer();
        $gate = $this->gate(['evil.example' => [self::PUBLIC_ADDRESS]], $dialer);

        $refusal = $this->refusal($gate, 'evil.example:443');

        self::assertSame(ProxyDecision::NotListed, $refusal->decision);
        self::assertSame('Airlock: blocked a connection to evil.example:443, which is not a package registry.', $refusal->getMessage());
        self::assertSame([], $dialer->dialed);
        self::assertSame(['evil.example:443 not_listed'], $this->decisions->lines);
    }

    public function testAListedHostOnAnotherPortIsRefused(): void
    {
        $gate = $this->gate(['registry.npmjs.org' => [self::PUBLIC_ADDRESS]], $this->dialer());

        self::assertSame(ProxyDecision::NotListed, $this->refusal($gate, 'registry.npmjs.org:22')->decision);
    }

    public function testAnAddressInPlaceOfANameIsRefused(): void
    {
        $gate = $this->gate([], $this->dialer());

        self::assertSame(ProxyDecision::NotListed, $this->refusal($gate, self::PUBLIC_ADDRESS.':443')->decision);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function privateAddresses(): iterable
    {
        yield 'loopback' => [['127.0.0.1']];
        yield 'a private network' => [['10.0.0.5']];
        yield 'another private network' => [['192.168.1.20']];
        yield 'the cloud metadata endpoint' => [['169.254.169.254']];
        yield 'shared address space' => [['100.64.0.1']];
        yield 'IPv6 loopback' => [['::1']];
        yield 'one private among public ones' => [[self::PUBLIC_ADDRESS, '10.0.0.5']];
    }

    /**
     * @param list<string> $addresses
     */
    #[DataProvider('privateAddresses')]
    public function testAListedNamePointingToAPrivateAddressIsRefused(array $addresses): void
    {
        $dialer = $this->dialer();
        $gate = $this->gate(['pypi.org' => $addresses], $dialer);

        $refusal = $this->refusal($gate, 'pypi.org:443');

        self::assertSame(ProxyDecision::PrivateAddress, $refusal->decision);
        self::assertSame([], $dialer->dialed);
        self::assertSame(['pypi.org:443 private_address'], $this->decisions->lines);
    }

    public function testAListedNameWithoutAnAddressIsRefused(): void
    {
        $gate = $this->gate([], $this->dialer());

        self::assertSame(ProxyDecision::Unresolved, $this->refusal($gate, 'crates.io:443')->decision);
        self::assertSame(['crates.io:443 unresolved'], $this->decisions->lines);
    }

    public function testTheNextAddressIsTriedWhenOneIsDown(): void
    {
        $dialer = $this->dialer(down: [self::PUBLIC_ADDRESS]);
        $gate = $this->gate(['rubygems.org' => [self::PUBLIC_ADDRESS, '151.101.64.4']], $dialer);

        $gate->open($this->target('rubygems.org:443'));

        self::assertSame([self::PUBLIC_ADDRESS.':443', '151.101.64.4:443'], $dialer->dialed);
        self::assertSame(['rubygems.org:443 allowed'], $this->decisions->lines);
    }

    public function testAHostWhoseAddressesAreAllDownIsUnreachable(): void
    {
        $gate = $this->gate(['rubygems.org' => [self::PUBLIC_ADDRESS]], $this->dialer(down: [self::PUBLIC_ADDRESS]));

        self::assertSame(ProxyDecision::Unreachable, $this->refusal($gate, 'rubygems.org:443')->decision);
        self::assertSame(['rubygems.org:443 unreachable'], $this->decisions->lines);
    }

    public function testLocalhostOnALocalPortTheUserOpenedIsThisMachine(): void
    {
        $dialer = $this->dialer();
        $gate = $this->gate([], $dialer, localPorts: [5432]);

        $gate->open($this->target('localhost:5432'));

        self::assertSame(['127.0.0.1:5432'], $dialer->dialed);
        self::assertSame(['localhost:5432 allowed'], $this->decisions->lines);
    }

    public function testLocalhostOnAnotherPortIsRefused(): void
    {
        $gate = $this->gate(['localhost' => ['127.0.0.1']], $this->dialer(), localPorts: [5432]);

        self::assertSame(ProxyDecision::NotListed, $this->refusal($gate, 'localhost:6379')->decision);
    }

    /**
     * @param array<string, list<string>> $addressesByHost
     * @param list<int>                   $localPorts
     */
    private function gate(array $addressesByHost, LocalDialer $dialer, array $localPorts = []): ProxyGate
    {
        return new ProxyGate(AllowedHosts::registries(), new FixedResolver($addressesByHost), $dialer, $this->decisions, $localPorts);
    }

    /**
     * @param list<string> $down
     */
    private function dialer(array $down = []): LocalDialer
    {
        return new LocalDialer('tcp://'.stream_socket_get_name($this->server, false), $down);
    }

    private function target(string $authority): ProxyTarget
    {
        return ProxyTarget::fromAuthority($authority, defaultPort: null);
    }

    private function refusal(ProxyGate $gate, string $authority): ProxyRefusedException
    {
        try {
            $gate->open($this->target($authority));
        } catch (ProxyRefusedException $e) {
            return $e;
        }

        self::fail(\sprintf('%s was not refused.', $authority));
    }
}
