<?php

declare(strict_types=1);

namespace App\Policy;

/**
 * The ways of reading which program a command runs.
 */
final class Invocations
{
    /**
     * The command itself, and when its program only runs another, every way of reading which one: a wrapper's own
     * options can take values (timeout -s KILL 5 rm -rf x), so any later word may be the program it runs.
     *
     * @param list<string> $words
     *
     * @return list<list<string>>
     */
    public static function of(array $words): array
    {
        if ([] === $words || !\in_array(basename($words[0]), CommandPolicy::WRAPPERS, true)) {
            return [$words];
        }

        $invocations = [$words];
        for ($start = 1, $count = \count($words); $start < $count; ++$start) {
            $invocations[] = \array_slice($words, $start);
        }

        return $invocations;
    }
}
