<?php

declare(strict_types=1);

namespace App\Sandbox;

/**
 * The end of a sandbox's description that says what its commands reach on the network.
 */
final class NetworkDescription
{
    public static function of(NetworkAccess $network): string
    {
        return match ($network) {
            NetworkAccess::Open => '',
            NetworkAccess::Registries => '; on the network, the package registries and the hosts network.yaml allows, but any host for a command reaching a git or SSH remote',
            NetworkAccess::None => '; no network',
        };
    }
}
