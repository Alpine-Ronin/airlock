<?php

declare(strict_types=1);

namespace App\Sandbox;

/**
 * What a sandboxed command can reach on the network.
 */
enum NetworkAccess
{
    case Open;
    /** The package registries only, through Airlock's proxy: see docs/network.md. */
    case Registries;
    case None;
}
