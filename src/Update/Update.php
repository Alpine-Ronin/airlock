<?php

declare(strict_types=1);

namespace App\Update;

/**
 * What airlock update did: the version it found installed, the one installed now, and whether its attestation was
 * checked.
 */
final readonly class Update
{
    public function __construct(
        public string $fromVersion,
        public string $toVersion,
        public ?Attestation $attestation,
    ) {
    }

    public static function alreadyInstalled(string $version): self
    {
        return new self($version, $version, null);
    }

    public function isAlreadyInstalled(): bool
    {
        return !$this->attestation instanceof Attestation;
    }
}
