<?php

declare(strict_types=1);

namespace App\Doctor;

use App\Sandbox\NetworkAccess;
use App\Sandbox\NoSandbox;
use App\Sandbox\Sandbox;

/**
 * What doctor says of the sandbox commands run in: none is a warning, as every command is then asked about; one that
 * leaves the network open is a warning too, as a command it runs can send what the project holds elsewhere; one that
 * keeps it to the package registries is not.
 */
final class SandboxFinding
{
    private const string TOPIC = 'Sandbox';

    public static function of(Sandbox $sandbox): Finding
    {
        if ($sandbox instanceof NoSandbox) {
            return Finding::warning(self::TOPIC, \sprintf('None: %s. A command would run with all your permissions, so Airlock asks before every one.', rtrim($sandbox->reason, '.')));
        }
        if (NetworkAccess::Open === $sandbox->network()) {
            return Finding::warning(self::TOPIC, $sandbox->describe().' Commands can reach the network: one an injection slipped in can send what the project holds elsewhere. AIRLOCK_SANDBOX=registries keeps it to the package registries; AIRLOCK_SANDBOX=strict cuts it off, for downloads and package installs too.');
        }

        return Finding::ok(self::TOPIC, $sandbox->describe());
    }
}
