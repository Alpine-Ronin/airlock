<?php

declare(strict_types=1);

namespace App\Network;

/**
 * Starts the proxy a sandboxed command reaches the network through: only the allowed hosts, see docs/network.md.
 */
final readonly class NetworkProxy
{
    public function __construct(
        private Resolver $resolver,
        private Dialer $dialer,
    ) {
    }

    /**
     * @param list<int> $localPorts the ports on 127.0.0.1 a command may reach, as `localhost`
     *
     * @throws NetworkStreamException
     */
    public function listen(ListenAddress $address, AllowedHosts $allowedHosts, DecisionLog $decisionLog, array $localPorts = []): ProxyServer
    {
        $listener = Streams::server($address->streamAddress);

        return new ProxyServer($listener, $address, new ProxyGate($allowedHosts, $this->resolver, $this->dialer, $decisionLog, $localPorts));
    }
}
