<?php

declare(strict_types=1);

namespace App\Network;

/**
 * The system's resolver, IPv4 only: what PHP can resolve without a warning, and what every listed registry serves.
 */
final readonly class SystemResolver implements Resolver
{
    #[\Override]
    public function addressesOf(string $host): array
    {
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $addresses = gethostbynamel($host);

        return false === $addresses ? [] : $addresses;
    }
}
