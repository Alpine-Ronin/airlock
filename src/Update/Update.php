<?php

declare(strict_types=1);

namespace App\Update;

/**
 * What airlock update found: the version installed, the one to install, whether its attestation was checked, and the
 * checked binary waiting beside the installed one.
 */
final readonly class Update
{
    public function __construct(
        public string $fromVersion,
        public string $toVersion,
        public ?Attestation $attestation,
        public ?string $checkedBinary,
    ) {
    }

    public static function alreadyInstalled(string $version): self
    {
        return new self($version, $version, null, null);
    }
}
