<?php

declare(strict_types=1);

namespace App\Network;

/**
 * What a sandboxed command limited to the registries may reach: the hosts, and the ports on this machine.
 */
final readonly class NetworkSettings
{
    /**
     * @param list<int<1, 65535>> $localPorts
     */
    public function __construct(
        public AllowedHosts $allowedHosts,
        public array $localPorts = [],
    ) {
    }
}
