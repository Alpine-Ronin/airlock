<?php

declare(strict_types=1);

namespace App\Grant;

/**
 * Adding grants to those kept, for the session or the project alike.
 */
final class GrantList
{
    /**
     * The grants kept, with the new ones in place of those they equal.
     *
     * @param list<Grant> $kept
     * @param list<Grant> $added
     *
     * @return list<Grant>
     */
    public static function with(array $kept, array $added): array
    {
        $others = array_filter($kept, static fn (Grant $grant): bool => !array_any($added, $grant->equals(...)));

        return [...array_values($others), ...$added];
    }
}
