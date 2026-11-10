<?php

declare(strict_types=1);

namespace App\Network;

/**
 * A host and port a command asked the proxy to reach, as the request named them: a host name in lower case without
 * its trailing dot, or an IP address.
 */
final readonly class ProxyTarget
{
    private const string HOST_NAME = '/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/';

    /**
     * @param int<1, 65535> $port
     */
    private function __construct(
        public string $host,
        public int $port,
    ) {
    }

    /**
     * `host:port`, `[ipv6]:port`, or the host alone when a default port is given.
     *
     * @param int<1, 65535>|null $defaultPort
     *
     * @throws MalformedProxyRequestException
     */
    public static function fromAuthority(string $authority, ?int $defaultPort): self
    {
        $matched = preg_match('/^(?:\[(?<ipv6>[0-9a-fA-F:.]+)\]|(?<name>[^:\[\]]+))(?::(?<port>[0-9]{1,5}))?$/', $authority, $parts);
        if (1 !== $matched) {
            throw new MalformedProxyRequestException(\sprintf('"%s" is not a host and port.', $authority));
        }

        return new self(self::host($parts['ipv6'] ?? '', $parts['name'] ?? ''), self::port($parts['port'] ?? '', $defaultPort, $authority));
    }

    public function authority(): string
    {
        if (str_contains($this->host, ':')) {
            return \sprintf('[%s]:%d', $this->host, $this->port);
        }

        return \sprintf('%s:%d', $this->host, $this->port);
    }

    /**
     * @throws MalformedProxyRequestException
     */
    private static function host(string $ipv6, string $name): string
    {
        if ('' !== $ipv6) {
            if (false === filter_var($ipv6, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
                throw new MalformedProxyRequestException(\sprintf('"%s" is not an IPv6 address.', $ipv6));
            }

            return strtolower($ipv6);
        }
        $host = rtrim(strtolower($name), '.');
        if (1 !== preg_match(self::HOST_NAME, $host)) {
            throw new MalformedProxyRequestException(\sprintf('"%s" is not a host name.', $name));
        }

        return $host;
    }

    /**
     * @param int<1, 65535>|null $defaultPort
     *
     * @return int<1, 65535>
     *
     * @throws MalformedProxyRequestException
     */
    private static function port(string $port, ?int $defaultPort, string $authority): int
    {
        if ('' === $port) {
            return $defaultPort ?? throw new MalformedProxyRequestException(\sprintf('"%s" names no port.', $authority));
        }
        $number = (int) $port;
        if ($number < 1 || $number > 65535) {
            throw new MalformedProxyRequestException(\sprintf('"%s" names no valid port.', $authority));
        }

        return $number;
    }
}
