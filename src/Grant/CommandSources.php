<?php

declare(strict_types=1);

namespace App\Grant;

use App\Execution\ProjectDirectory;
use App\Policy\CommandPolicy;
use App\Shell\CommandLine;

/**
 * The project files that decide what a command does: the Makefile make reads, the package.json or composer.json a
 * package manager runs scripts from, the script an interpreter or a relative path runs. A grant records them, so that
 * an edit to one asks about the command again: a granted `make test` runs whatever the Makefile says now.
 *
 * Only project files count: a command can write nowhere else. What those files load in turn (a Makefile's include, a
 * script another one calls) is not followed.
 */
final readonly class CommandSources
{
    private const array MAKES = ['make', 'gmake'];

    /** The three names make looks for, in its order: every one present counts, whichever make picks. */
    private const array MAKEFILES = ['GNUmakefile', 'makefile', 'Makefile'];

    private const array PACKAGE_MANAGERS = ['npm', 'pnpm', 'yarn', 'bun'];

    public function __construct(
        private ProjectDirectory $projectDirectory,
    ) {
    }

    /**
     * For each simple command of the line, in order, the sources it reads. A `cd` on the line moves the commands after
     * it: their sources are looked for in every directory they may run from.
     *
     * @return list<list<Source>>
     */
    public function of(CommandLine $line): array
    {
        $directories = [$this->projectDirectory->path];
        $sources = [];
        foreach ($line->commands as $command) {
            $words = $command->programWords();
            $sources[] = $this->hashed($this->candidates($words, $directories));
            $directories = $this->afterChangingDirectory($words, $directories);
        }

        return $sources;
    }

    /**
     * @param list<string>           $words
     * @param non-empty-list<string> $directories
     *
     * @return list<string>
     */
    private function candidates(array $words, array $directories): array
    {
        if ([] === $words) {
            return [];
        }
        $candidates = [];
        foreach ($directories as $directory) {
            array_push($candidates, ...$this->candidatesFrom($words, $directory));
        }

        return $candidates;
    }

    /**
     * @param non-empty-list<string> $words
     *
     * @return list<string>
     */
    private function candidatesFrom(array $words, string $directory): array
    {
        if (str_contains($words[0], '/')) {
            return [$this->resolve($directory, $words[0])];
        }
        $program = basename($words[0]);
        if (\in_array($program, self::MAKES, true)) {
            return $this->makefiles($words, $directory);
        }
        if (\in_array($program, self::PACKAGE_MANAGERS, true)) {
            return [$this->resolve($this->movedTo($words, ['--prefix', '-C', '--dir', '--cwd'], $directory), 'package.json')];
        }
        if ('composer' === $program) {
            return [$this->resolve($this->movedTo($words, ['-d', '--working-dir'], $directory), 'composer.json')];
        }
        if (\in_array($program, CommandPolicy::INTERPRETERS, true) && isset($words[1]) && !str_starts_with($words[1], '-')) {
            return [$this->resolve($directory, $words[1])];
        }

        return [];
    }

    /**
     * @param non-empty-list<string> $words
     *
     * @return list<string>
     */
    private function makefiles(array $words, string $directory): array
    {
        $makeDirectory = $this->movedTo($words, ['-C', '--directory'], $directory);
        $named = $this->optionValue($words, ['-f', '--file', '--makefile']);
        if (null !== $named) {
            return [$this->resolve($makeDirectory, $named)];
        }

        return array_map(fn (string $name): string => $this->resolve($makeDirectory, $name), self::MAKEFILES);
    }

    /**
     * @param list<string>           $words
     * @param non-empty-list<string> $names
     */
    private function movedTo(array $words, array $names, string $directory): string
    {
        $moved = $this->optionValue($words, $names);

        return null === $moved ? $directory : $this->resolve($directory, $moved);
    }

    /**
     * The value given to one of these options, as `-C dir`, `-Cdir` or `--directory=dir`.
     *
     * @param list<string>           $words
     * @param non-empty-list<string> $names
     */
    private function optionValue(array $words, array $names): ?string
    {
        foreach ($words as $index => $word) {
            foreach ($names as $name) {
                if ($word === $name) {
                    return $words[$index + 1] ?? null;
                }
                $isLong = str_starts_with($name, '--');
                if ($isLong && str_starts_with($word, $name.'=')) {
                    return substr($word, \strlen($name) + 1);
                }
                if (!$isLong && str_starts_with($word, $name) && \strlen($word) > \strlen($name)) {
                    return substr($word, \strlen($name));
                }
            }
        }

        return null;
    }

    /**
     * @param list<string>           $words
     * @param non-empty-list<string> $directories
     *
     * @return non-empty-list<string>
     */
    private function afterChangingDirectory(array $words, array $directories): array
    {
        $isCd = 2 === \count($words) && 'cd' === $words[0] && '-' !== $words[1];
        if (!$isCd) {
            return $directories;
        }
        $after = $directories;
        foreach ($directories as $directory) {
            $after[] = $this->resolve($directory, $words[1]);
        }

        return array_values(array_unique($after));
    }

    private function resolve(string $directory, string $path): string
    {
        return str_starts_with($path, '/') ? $path : $directory.'/'.$path;
    }

    /**
     * @param list<string> $candidates
     *
     * @return list<Source>
     */
    private function hashed(array $candidates): array
    {
        $sources = [];
        foreach ($candidates as $candidate) {
            $source = $this->source($candidate);
            if ($source instanceof Source && !array_any($sources, static fn (Source $kept): bool => $kept->path === $source->path)) {
                $sources[] = $source;
            }
        }

        return $sources;
    }

    private function source(string $candidate): ?Source
    {
        $real = realpath($candidate);
        $project = $this->projectDirectory->path;
        if (false === $real || !is_file($real) || !str_starts_with($real, $project.'/')) {
            return null;
        }
        $sha256 = is_readable($real) ? hash_file('sha256', $real) : false;

        return new Source(substr($real, \strlen($project) + 1), false === $sha256 ? '' : $sha256);
    }
}
