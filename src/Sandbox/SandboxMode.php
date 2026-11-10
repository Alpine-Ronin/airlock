<?php

declare(strict_types=1);

namespace App\Sandbox;

enum SandboxMode: string
{
    /**
     * Writes kept to the project, /tmp and package caches; Airlock's files and the user's keys hidden; the network
     * limited to the package registries and the hosts the user allowed.
     */
    case Auto = 'auto';
    /** The same as auto, by the name it had while it was not the default. */
    case Registries = 'registries';
    /** As auto, with any host on the network. */
    case Open = 'open';
    /** As auto, with no network either. */
    case Strict = 'strict';
    /** No sandbox: every command is asked about. */
    case Off = 'off';

    public function network(): NetworkAccess
    {
        return match ($this) {
            self::Auto, self::Registries => NetworkAccess::Registries,
            self::Open, self::Off => NetworkAccess::Open,
            self::Strict => NetworkAccess::None,
        };
    }
}
