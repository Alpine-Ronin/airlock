<?php

declare(strict_types=1);

namespace App\Policy;

/**
 * What the policy makes of a whole command line: its worst part decides.
 */
final readonly class Assessment
{
    /**
     * @param list<string> $reasons                    why it is not plain, one per concern, naming the part concerned
     * @param bool         $needsSshAgent              whether a part talks to another machine, so may need the user's SSH
     *                                                 agent and the open network
     * @param bool         $mixesARemoteWithOtherParts whether a part talks to another machine and another part, but a
     *                                                 plain `cd`, does not: the open network would reach that part too
     */
    public function __construct(
        public Verdict $verdict,
        public array $reasons = [],
        public bool $needsSshAgent = false,
        public bool $mixesARemoteWithOtherParts = false,
    ) {
    }
}
