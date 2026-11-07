<?php

declare(strict_types=1);

namespace App\Grant;

use App\Execution\ProjectDirectory;
use App\Policy\CommandPolicy;
use App\Policy\Invocations;
use App\Shell\CommandLine;
use App\Shell\SimpleCommand;

/**
 * The project files that decide what a command does: the Makefiles make reads, the package.json or composer.json a
 * package manager runs scripts from, the scripts an interpreter or a path runs. A grant records them, so that an edit
 * to one asks about the command again: a granted `make test` runs whatever the Makefile says now.
 *
 * Only project files count: a command can write nowhere else. What those files load in turn (a Makefile's include, a
 * script another one calls) and code given inline to an interpreter (`bash -c '…'`) are not followed.
 */
final readonly class CommandSources
{
    private const array MAKES = ['make', 'gmake'];

    /** The three names make looks for, in its order: every one present counts, whichever make picks. */
    private const array MAKEFILES = ['GNUmakefile', 'makefile', 'Makefile'];

    /** make's short options that take a value, in the same word or the next: -C and -f say which Makefiles it reads. */
    private const string MAKE_VALUE_OPTIONS = 'CfIoW';

    /** make's short options that take an optional number, written in the same word only. */
    private const string MAKE_NUMBER_OPTIONS = 'jl';

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
        $sources = [];
        foreach ($this->readsPerCommand($line) as [$candidates]) {
            $sources[] = $this->hashed($candidates);
        }

        return $sources;
    }

    /**
     * Whether the line can change a source while the command reading it has not read it yet: a command before it
     * (other than a plain cd), one running alongside it (later in its pipeline, or after it once it runs in the
     * background), or its own redirection. What a grant vouched for is then checked too early to mean anything.
     */
    public function canChangeBeforeRead(CommandLine $line): bool
    {
        foreach ($this->readsPerCommand($line) as $index => [$candidates, $writesOwnSource]) {
            if ([] === $candidates) {
                continue;
            }
            $others = [...\array_slice($line->commands, 0, $index), ...$this->runningAlongside($line, $index)];
            if ($writesOwnSource || !array_all($others, $this->isPlainCd(...))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The commands after this one that may run while it does: the rest of its pipeline, and everything after the first
     * lone `&` from it on. A `&` sends a whole list to the background (`make && ls & cp …`), and the parser keeps no
     * list boundaries: counting what follows any later `&` asks at worst once more than needed.
     *
     * @return list<SimpleCommand>
     */
    private function runningAlongside(CommandLine $line, int $index): array
    {
        $commands = $line->commands;
        $last = $index;
        while (isset($commands[$last + 1]) && $commands[$last + 1]->readsPipe) {
            ++$last;
        }
        $alongside = \array_slice($commands, $index + 1, $last - $index);
        foreach (\array_slice($commands, $index, null, true) as $position => $command) {
            if ($command->runsInBackground) {
                return [...$alongside, ...\array_slice($commands, $position + 1)];
            }
        }

        return $alongside;
    }

    /**
     * For each command, every path it may read a source from, present or not (one the line creates counts as much),
     * and whether one of its own redirections writes to one of them.
     *
     * @return list<array{list<string>, bool}>
     */
    private function readsPerCommand(CommandLine $line): array
    {
        $directories = [$this->projectDirectory->path];
        $reads = [];
        foreach ($line->commands as $command) {
            $words = $command->programWords();
            $candidates = $this->candidates($words, $directories);
            $reads[] = [$candidates, $this->writesOneOf($command, $candidates, $directories)];
            $directories = $this->afterChangingDirectory($words, $directories);
        }

        return $reads;
    }

    /**
     * @param list<string>           $words
     * @param non-empty-list<string> $directories
     *
     * @return list<string>
     */
    private function candidates(array $words, array $directories): array
    {
        $candidates = [];
        $runFrom = $this->withEnvDirectories($words, $directories);
        foreach (Invocations::of($words) as $invocation) {
            if ([] === $invocation) {
                continue;
            }
            foreach ($runFrom as $directory) {
                array_push($candidates, ...$this->candidatesFrom($invocation, $directory));
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * The directories, and those an `env -C dir` (or `--chdir`) wrapping the program moves it to.
     *
     * @param list<string>           $words
     * @param non-empty-list<string> $directories
     *
     * @return non-empty-list<string>
     */
    private function withEnvDirectories(array $words, array $directories): array
    {
        if ('env' !== basename($words[0] ?? '')) {
            return $directories;
        }
        $runFrom = $directories;
        foreach ($this->envDirectories($words) as $moved) {
            foreach ($directories as $directory) {
                $runFrom[] = $this->resolve($directory, $moved);
            }
        }

        return array_values(array_unique($runFrom));
    }

    /**
     * Every directory env's -C or --chdir names, as `-C dir`, `-Cdir`, clustered (`-iC dir`) or `--chdir=dir`.
     *
     * @param list<string> $words
     *
     * @return list<string>
     */
    private function envDirectories(array $words): array
    {
        $directories = [];
        for ($index = 1, $count = \count($words); $index < $count; ++$index) {
            $word = $words[$index];
            if (str_starts_with($word, '--chdir=')) {
                $directories[] = substr($word, \strlen('--chdir='));
            }
            if ('--chdir' === $word) {
                $directories[] = $words[++$index] ?? '';
            }
            if (1 === preg_match('/^-[A-Za-z]*?C(.*)$/', $word, $cluster)) {
                $directories[] = '' === $cluster[1] ? ($words[++$index] ?? '') : $cluster[1];
            }
        }

        return array_values(array_filter($directories, static fn (string $directory): bool => '' !== $directory));
    }

    /**
     * @param non-empty-list<string> $words
     *
     * @return list<string>
     */
    private function candidatesFrom(array $words, string $directory): array
    {
        $candidates = str_contains($words[0], '/') ? [$this->resolve($directory, $words[0])] : [];
        $program = basename($words[0]);
        if (\in_array($program, self::MAKES, true)) {
            return [...$candidates, ...$this->makefiles($words, $directory)];
        }
        if (\in_array($program, self::PACKAGE_MANAGERS, true)) {
            return [...$candidates, $this->resolve($this->movedTo($words, ['--prefix', '-C', '--dir', '--cwd'], $directory), 'package.json')];
        }
        if ('composer' === $program) {
            return [...$candidates, $this->resolve($this->movedTo($words, ['-d', '--working-dir'], $directory), 'composer.json')];
        }
        if (\in_array($program, CommandPolicy::INTERPRETERS, true)) {
            return [...$candidates, ...$this->scripts($words, $directory)];
        }

        return $candidates;
    }

    /**
     * Every argument of an interpreter that may name a file it runs, past its options: any of them that is a project
     * file counts, as an option's value cannot be told from the script. Inline code given before any of them runs no
     * file; after one, such an option is the script's own (`php bin/console x -e prod`).
     *
     * @param non-empty-list<string> $words
     *
     * @return list<string>
     */
    private function scripts(array $words, string $directory): array
    {
        $scripts = [];
        foreach (\array_slice($words, 1) as $argument) {
            if ([] === $scripts && \in_array($argument, CommandPolicy::INLINE_CODE_OPTIONS, true)) {
                return [];
            }
            if (!str_starts_with($argument, '-')) {
                $scripts[] = $this->resolve($directory, $argument);
            }
        }

        return $scripts;
    }

    /**
     * The Makefiles make reads: those named with -f, else the usual names, in the directory every -C moves it to in
     * turn.
     *
     * @param non-empty-list<string> $words
     *
     * @return list<string>
     */
    private function makefiles(array $words, string $directory): array
    {
        $makeDirectory = $directory;
        $named = [];
        foreach ($this->makeOptions($words) as [$option, $value]) {
            if ('C' === $option) {
                $makeDirectory = $this->resolve($makeDirectory, $value);
            }
            if ('f' === $option) {
                $named[] = $value;
            }
        }
        $files = [] === $named ? self::MAKEFILES : $named;

        return array_map(fn (string $file): string => $this->resolve($makeDirectory, $file), $files);
    }

    /**
     * make's -C and -f options in order, as `-C dir`, `-Cdir`, clustered (`-sC dir`) or long (`--directory=dir`).
     *
     * @param non-empty-list<string> $words
     *
     * @return list<array{string, string}>
     */
    private function makeOptions(array $words): array
    {
        $long = ['--directory' => 'C', '--file' => 'f', '--makefile' => 'f'];
        $options = [];
        for ($index = 1, $count = \count($words); $index < $count; ++$index) {
            $word = $words[$index];
            if ('--' === $word) {
                break;
            }
            if (str_starts_with($word, '--')) {
                $parts = explode('=', $word, 2);
                $name = $parts[0];
                $value = $parts[1] ?? null;
                if (isset($long[$name])) {
                    $value ??= $words[++$index] ?? '';
                    $options[] = [$long[$name], $value];
                }

                continue;
            }
            if (str_starts_with($word, '-')) {
                [$cluster, $usesNextWord] = $this->makeCluster($word, $words[$index + 1] ?? '');
                array_push($options, ...$cluster);
                $index += $usesNextWord ? 1 : 0;
            }
        }

        return $options;
    }

    /**
     * The -C and -f options of one cluster of short options, and whether the last one took the next word as its value.
     *
     * @return array{list<array{string, string}>, bool}
     */
    private function makeCluster(string $word, string $nextWord): array
    {
        $length = \strlen($word);
        for ($position = 1; $position < $length; ++$position) {
            $letter = $word[$position];
            if (str_contains(self::MAKE_NUMBER_OPTIONS, $letter)) {
                $position += strspn($word, '0123456789', $position + 1);

                continue;
            }
            if (!str_contains(self::MAKE_VALUE_OPTIONS, $letter)) {
                continue;
            }
            $rest = substr($word, $position + 1);
            $value = '' === $rest ? $nextWord : $rest;
            $options = \in_array($letter, ['C', 'f'], true) ? [[$letter, $value]] : [];

            return [$options, '' === $rest];
        }

        return [[], false];
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
     * The value given to one of these options, as `-C dir`, `-Cdir` or `--prefix=dir`.
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
        $target = $this->cdTarget($words);
        if (null === $target) {
            return $directories;
        }
        $after = $directories;
        foreach ($directories as $directory) {
            $after[] = $this->resolve($directory, $target);
        }

        return array_values(array_unique($after));
    }

    /**
     * The directory a cd moves to, past its options (-L, -P, -e, -@) and `--`: null for any other command, and for a
     * cd to the home or the previous directory, outside the project.
     *
     * @param list<string> $words
     */
    private function cdTarget(array $words): ?string
    {
        if ('cd' !== ($words[0] ?? null)) {
            return null;
        }
        $operands = \array_slice($words, 1);
        while ([] !== $operands && 1 === preg_match('/^-[LPe@]+$/', $operands[0])) {
            array_shift($operands);
        }
        if ('--' === ($operands[0] ?? null)) {
            array_shift($operands);
        }
        $isOneDirectory = 1 === \count($operands) && '-' !== $operands[0];

        return $isOneDirectory ? $operands[0] : null;
    }

    private function isPlainCd(SimpleCommand $command): bool
    {
        return 'cd' === ($command->programWords()[0] ?? null) && [] === $command->redirections;
    }

    /**
     * @param list<string>           $candidates
     * @param non-empty-list<string> $directories
     */
    private function writesOneOf(SimpleCommand $command, array $candidates, array $directories): bool
    {
        foreach ($command->redirections as $redirection) {
            if (!str_contains($redirection->operator, '>')) {
                continue;
            }
            foreach ($directories as $directory) {
                $target = $this->canonical($this->resolve($directory, $redirection->target));
                if (array_any($candidates, fn (string $candidate): bool => $this->canonical($candidate) === $target)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The path, absolute. Its `..` stay: through a symbolic link they lead where the link points, which only the
     * filesystem knows, and realpath() reads them when the file is hashed.
     */
    private function resolve(string $directory, string $path): string
    {
        $absolute = str_starts_with($path, '/') ? $path : $directory.'/'.$path;

        return '/'.implode('/', array_filter(explode('/', $absolute), static fn (string $segment): bool => '' !== $segment && '.' !== $segment));
    }

    /**
     * One spelling per file, present or not: the file as the filesystem resolves it, a symbolic link to it included,
     * else its directory so resolved, then its name.
     */
    private function canonical(string $path): string
    {
        $file = realpath($path);
        if (false !== $file) {
            return $file;
        }
        $directory = realpath(\dirname($path));

        return (false === $directory ? \dirname($path) : $directory).'/'.basename($path);
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
