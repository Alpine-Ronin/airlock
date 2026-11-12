<?php

declare(strict_types=1);

namespace App\Update;

enum Attestation
{
    /** The release workflow of Airlock's repository built the binary, from the release's tag. */
    case Verified;

    /** The GitHub CLI is not there to check it, or the release predates attestations. */
    case NotChecked;
}
