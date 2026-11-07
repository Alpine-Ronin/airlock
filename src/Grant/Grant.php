<?php

declare(strict_types=1);

namespace App\Grant;

use App\Shell\SimpleCommand;

/**
 * Lets one simple command run without asking: exactly these words, or these words followed by any others, as long as
 * the project files deciding what it does are as they were when it was given.
 */
final readonly class Grant
{
    /**
     * @param non-empty-list<string> $words
     * @param list<Source>           $sources the files deciding what the command did when it was given
     */
    public function __construct(
        public array $words,
        public bool $coversLongerCommands,
        public array $sources = [],
    ) {
    }

    public function covers(SimpleCommand $command): bool
    {
        if (!$this->coversLongerCommands) {
            return $command->words === $this->words;
        }

        return \array_slice($command->words, 0, \count($this->words)) === $this->words;
    }

    /**
     * The paths of the sources a command reads now that this grant does not vouch for: changed since it was given,
     * or not read by the command then.
     *
     * @param list<Source> $current
     *
     * @return list<string>
     */
    public function staleSources(array $current): array
    {
        $stale = [];
        foreach ($current as $source) {
            if (!array_any($this->sources, $source->matches(...))) {
                $stale[] = $source->path;
            }
        }

        return $stale;
    }

    /**
     * The grant as the user reads it: `make test …` for the prefix form, the command itself otherwise.
     */
    public function describe(): string
    {
        $command = implode(' ', array_map($this->quote(...), $this->words));

        return $this->coversLongerCommands ? $command.' …' : $command;
    }

    public function equals(self $other): bool
    {
        return $this->words === $other->words && $this->coversLongerCommands === $other->coversLongerCommands;
    }

    private function quote(string $word): string
    {
        if (1 === preg_match('#^[A-Za-z0-9_@%+=:,./-]+$#', $word)) {
            return $word;
        }

        return "'".str_replace("'", "'\\''", $word)."'";
    }
}
