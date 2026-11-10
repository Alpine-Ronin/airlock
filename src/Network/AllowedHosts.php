<?php

declare(strict_types=1);

namespace App\Network;

/**
 * The hosts and ports a sandboxed command may reach through the proxy: exact names, no wildcard, since a wildcard on
 * a domain that hosts users' content is an upload service.
 */
final readonly class AllowedHosts
{
    /**
     * The package registries, and what they download from. `api.github.com` takes writes with a token too, but
     * Packagist points most packages' downloads at it: see docs/network.md.
     */
    private const array REGISTRIES = [
        'repo.packagist.org', 'packagist.org', 'getcomposer.org',
        'registry.npmjs.org', 'registry.yarnpkg.com',
        'pypi.org', 'files.pythonhosted.org',
        'index.crates.io', 'static.crates.io', 'crates.io',
        'proxy.golang.org', 'sum.golang.org',
        'rubygems.org', 'index.rubygems.org',
        'api.github.com', 'codeload.github.com', 'objects.githubusercontent.com',
    ];

    private const int HTTPS = 443;

    /**
     * @param array<string, list<int>> $portsByHost
     */
    public function __construct(
        private array $portsByHost,
    ) {
    }

    public static function registries(): self
    {
        return new self(array_fill_keys(self::REGISTRIES, [self::HTTPS]));
    }

    /**
     * @param list<string> $authorities each host and port, as host:port
     *
     * @throws MalformedProxyRequestException
     */
    public static function fromAuthorities(array $authorities): self
    {
        $hosts = new self([]);
        foreach ($authorities as $authority) {
            $hosts = $hosts->with(ProxyTarget::fromAuthority($authority, defaultPort: null));
        }

        return $hosts;
    }

    public function allows(ProxyTarget $target): bool
    {
        return \in_array($target->port, $this->portsByHost[$target->host] ?? [], true);
    }

    public function with(ProxyTarget $target): self
    {
        $portsByHost = $this->portsByHost;
        $ports = $portsByHost[$target->host] ?? [];
        if (!\in_array($target->port, $ports, true)) {
            $ports[] = $target->port;
        }
        $portsByHost[$target->host] = $ports;

        return new self($portsByHost);
    }

    /**
     * The same hosts, but this one, on every port.
     */
    public function without(string $host): self
    {
        $portsByHost = $this->portsByHost;
        unset($portsByHost[$host]);

        return new self($portsByHost);
    }

    /**
     * @return list<string>
     */
    public function authorities(): array
    {
        $authorities = [];
        foreach ($this->portsByHost as $host => $ports) {
            foreach ($ports as $port) {
                $name = str_contains($host, ':') ? '['.$host.']' : $host;
                $authorities[] = \sprintf('%s:%d', $name, $port);
            }
        }

        return $authorities;
    }
}
