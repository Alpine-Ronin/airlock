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
            NetworkAccess::Registries => '; on the network, the package registries only',
            NetworkAccess::None => '; no network',
        };
    }
}
