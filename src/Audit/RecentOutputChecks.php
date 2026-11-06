<?php

declare(strict_types=1);

namespace App\Audit;

/**
 * How the output check fared on a project's last decisions: how many outputs went through it, how many it timed out on.
 */
final readonly class RecentOutputChecks
{
    /** From this many timeouts, the model is too slow to rely on: outputs keep reaching the agent unchecked. */
    private const int REPEATED_TIMEOUTS = 3;

    public function __construct(
        public int $outputs,
        public int $timedOut,
    ) {
    }

    public function keepTimingOut(): bool
    {
        return $this->timedOut >= self::REPEATED_TIMEOUTS;
    }

    public function warning(): string
    {
        return \sprintf(
            'The output check timed out on %d of the last %d outputs in this project: they reached the agent unchecked. The model may be too slow now: run airlock configure --fastest to pick a faster one.',
            $this->timedOut,
            $this->outputs,
        );
    }
}
