<?php

declare(strict_types=1);

namespace App\Grant;

use App\Policy\CommandPolicy;
use App\Shell\CommandLine;
use App\Shell\SimpleCommand;

/**
 * What a grant for a command covers: its program and first argument followed by anything (`make test …`), or exactly
 * the command when its first word after the program does not say what it will do.
 */
final readonly class GrantRules
{
    /**
     * Programs whose first argument does not bound what they do: a grant for one of them covers that exact command.
     */
    private const array EXACT_ONLY = [
        'find', 'curl', 'wget', 'docker', 'podman', 'kubectl', 'ssh', 'scp', 'rsync', 'npx', 'pnpx', 'bunx', 'sed', 'awk',
        'git',
    ];

    public function __construct(
        private CommandSources $sources,
    ) {
    }

    /**
     * The grants that would let every part of the line run again unasked, one per simple command, with the sources
     * each reads now.
     *
     * @return list<Grant>
     */
    public function for(CommandLine $line): array
    {
        $sources = $this->sources->of($line);
        $grants = [];
        foreach ($line->commands as $index => $command) {
            $grant = $this->forCommand($command, $sources[$index]);
            if ($grant instanceof Grant && !array_any($grants, $grant->equals(...))) {
                $grants[] = $grant;
            }
        }

        return $grants;
    }

    /**
     * @param list<Source> $sources
     */
    private function forCommand(SimpleCommand $command, array $sources): ?Grant
    {
        $words = $command->words;
        $programWords = $command->programWords();
        if ([] === $words || [] === $programWords) {
            return null;
        }

        $firstArgument = $programWords[1] ?? null;
        if (null === $firstArgument || $this->isExactOnly($programWords[0], $firstArgument)) {
            return new Grant($words, coversLongerCommands: false, sources: $sources);
        }

        $assignments = \array_slice($words, 0, \count($words) - \count($programWords));

        return new Grant([...$assignments, $programWords[0], $firstArgument], coversLongerCommands: true, sources: $sources);
    }

    private function isExactOnly(string $programPath, string $firstArgument): bool
    {
        $program = basename($programPath);

        return str_starts_with($firstArgument, '-')
            || \in_array($program, self::EXACT_ONLY, true)
            || \in_array($program, CommandPolicy::WRAPPERS, true)
            || (\in_array($program, CommandPolicy::INTERPRETERS, true) && !$this->namesAScript($firstArgument));
    }

    /**
     * Whether an interpreter's first argument is a file it runs (`php bin/console`), not a command or code.
     */
    private function namesAScript(string $argument): bool
    {
        return str_contains($argument, '/') || str_contains($argument, '.');
    }
}
