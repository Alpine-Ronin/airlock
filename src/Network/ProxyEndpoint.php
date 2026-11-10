<?php

declare(strict_types=1);

namespace App\Network;

/**
 * Where a running proxy listens, as it announced it: a Unix socket's file, or a port on 127.0.0.1.
 */
final readonly class ProxyEndpoint
{
    /**
     * The port bubblewrap's relay listens on, in the command's own network namespace: nothing else listens there when
     * it starts, and no local port a network file lists may take it.
     */
    public const int RELAY_PORT = 3128;

    /** The variables tools read their proxy from; a command's own local servers stay direct. */
    private const array PROXY_VARIABLES = ['HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'http_proxy', 'https_proxy', 'all_proxy'];

    private const array DIRECT_VARIABLES = ['NO_PROXY', 'no_proxy'];

    /**
     * @param int<1, 65535>|null  $port
     * @param list<int<1, 65535>> $localPorts the ports on this machine a command may reach besides the proxy
     */
    private function __construct(
        public ?string $socketFile,
        public ?int $port,
        public array $localPorts = [],
    ) {
    }

    /**
     * @param list<int<1, 65535>> $localPorts
     */
    public function withLocalPorts(array $localPorts): self
    {
        return new self($this->socketFile, $this->port, $localPorts);
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function fromAnnounced(string $address): self
    {
        if (str_starts_with($address, '/')) {
            return new self($address, null);
        }
        if (1 === preg_match('/^127\.0\.0\.1:(?<port>[0-9]{1,5})$/', $address, $local)) {
            $port = (int) $local['port'];
            if ($port >= 1 && $port <= 65535) {
                return new self(null, $port);
            }
        }

        throw new \InvalidArgumentException(\sprintf('The proxy announced "%s", neither a socket file nor 127.0.0.1:<port>.', $address));
    }

    /**
     * What tells a command's tools to go through a proxy listening on 127.0.0.1 at $port.
     *
     * @return array<string, string>
     */
    public static function variablesFor(int $port): array
    {
        $variables = array_fill_keys(self::PROXY_VARIABLES, \sprintf('http://127.0.0.1:%d', $port));

        return [...$variables, ...array_fill_keys(self::DIRECT_VARIABLES, 'localhost,127.0.0.1')];
    }
}
