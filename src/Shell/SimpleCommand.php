<?php

declare(strict_types=1);

namespace App\Shell;

/**
 * One program run with its arguments: what sits between two operators of a command line.
 */
final readonly class SimpleCommand
{
    /**
     * @param list<string>      $words            the program and its arguments, unquoted, variable assignments first
     * @param list<Redirection> $redirections
     * @param bool              $readsPipe        whether its input is the output of the command before it
     * @param int               $assignments      how many of the first words set a variable for the command
     * @param bool              $runsInBackground whether a lone `&` follows it: what comes next runs alongside
     */
    public function __construct(
        public array $words,
        public array $redirections = [],
        public bool $readsPipe = false,
        public int $assignments = 0,
        public bool $runsInBackground = false,
    ) {
    }

    /**
     * The words after the variables set for this command: its program first.
     *
     * @return list<string>
     */
    public function programWords(): array
    {
        return \array_slice($this->words, $this->assignments);
    }
}
