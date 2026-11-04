<?php

declare(strict_types=1);

namespace App\Doctor;

enum Health
{
    /** As it should be. */
    case Ok;
    /** Airlock works, with less protection or help than it could give. */
    case Warning;
    /** Airlock refuses every command, or its own files are open to others, until this is fixed. */
    case Problem;
}
