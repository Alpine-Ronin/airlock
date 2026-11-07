<?php

declare(strict_types=1);

namespace App\Grant;

use App\Shell\CommandLine;
use App\Shell\SimpleCommand;

/**
 * Whether the grants given so far, for this session or for the project, let a line run without asking.
 */
final readonly class Grants
{
    public function __construct(
        private SessionGrants $session,
        private ProjectGrants $project,
        private CommandSources $sources,
    ) {
    }

    /**
     * Every simple command of the line covered by a grant that vouches for the sources it reads now, on a line that
     * cannot change them first. Whether the line may be covered at all is the policy's call: a line asked about every
     * time never is.
     *
     * @throws InvalidGrantsException
     */
    public function covers(CommandLine $line): bool
    {
        if ([] === $line->commands || $this->sources->canChangeBeforeRead($line)) {
            return false;
        }
        $grants = $this->all();
        $sources = $this->sources->of($line);
        foreach ($line->commands as $index => $command) {
            $isCovered = array_any($grants, static fn (Grant $grant): bool => $grant->covers($command) && [] === $grant->staleSources($sources[$index]));
            if (!$isCovered) {
                return false;
            }
        }

        return true;
    }

    /**
     * Why grants covering every command's words still do not let the line run; null when one command's words are
     * covered by no grant at all, or when the line is covered.
     *
     * @throws InvalidGrantsException
     */
    public function miss(CommandLine $line): ?GrantMiss
    {
        if ([] === $line->commands || $this->covers($line)) {
            return null;
        }
        $grants = $this->all();
        $isEveryCommandGranted = array_all($line->commands, static fn (SimpleCommand $command): bool => array_any($grants, static fn (Grant $grant): bool => $grant->covers($command)));
        if (!$isEveryCommandGranted) {
            return null;
        }
        $stale = $this->staleSources($line);
        if ([] !== $stale) {
            return GrantMiss::changedSources($stale);
        }

        return GrantMiss::changeableBeforeRead();
    }

    /**
     * The sources that keep a grant from covering the line: a grant covers a command's words, but not what it reads
     * now. Empty when no grant covers the words, or when one covers them fully.
     *
     * @return list<string>
     *
     * @throws InvalidGrantsException
     */
    public function staleSources(CommandLine $line): array
    {
        $grants = $this->all();
        $sources = $this->sources->of($line);
        $stale = [];
        foreach ($line->commands as $index => $command) {
            array_push($stale, ...$this->staleFor($command, $grants, $sources[$index]));
        }

        return array_values(array_unique($stale));
    }

    /**
     * @param list<Grant>  $grants
     * @param list<Source> $sources
     *
     * @return list<string>
     */
    private function staleFor(SimpleCommand $command, array $grants, array $sources): array
    {
        $stale = [];
        foreach ($grants as $grant) {
            if (!$grant->covers($command)) {
                continue;
            }
            $staleForGrant = $grant->staleSources($sources);
            if ([] === $staleForGrant) {
                return [];
            }
            array_push($stale, ...$staleForGrant);
        }

        return $stale;
    }

    /**
     * @return list<Grant>
     *
     * @throws InvalidGrantsException
     */
    private function all(): array
    {
        return [...$this->session->all(), ...$this->project->all()];
    }
}
