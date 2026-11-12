<?php

declare(strict_types=1);

namespace App\Update;

/**
 * Checks where a downloaded binary was built.
 */
interface AttestationCheck
{
    /**
     * @throws UpdateFailedException when the binary has no attestation that Airlock's release workflow built it
     */
    public function check(string $binaryPath, string $tag): Attestation;
}
