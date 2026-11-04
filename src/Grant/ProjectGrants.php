<?php

declare(strict_types=1);

namespace App\Grant;

use App\Configuration\ConfigDirectory;
use App\Execution\ProjectDirectory;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Grants kept for the project, in Airlock's directory, where no command can read or change them. Written under a
 * lock: two clients can share one project, each with its own Airlock.
 */
final readonly class ProjectGrants
{
    public function __construct(
        private ConfigDirectory $configDirectory,
        private ProjectDirectory $projectDirectory,
        private LockFactory $lockFactory,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @return list<Grant>
     *
     * @throws InvalidGrantsException when the file cannot be read: it is never guessed at
     */
    public function all(): array
    {
        $file = $this->file();
        if (!is_file($file)) {
            return [];
        }

        try {
            $content = Yaml::parseFile($file);
        } catch (ParseException $e) {
            throw new InvalidGrantsException(\sprintf('The grants file %s is not valid YAML: %s', $file, $e->getMessage()), $e->getCode(), previous: $e);
        }

        return $this->grantsIn($content, $file);
    }

    /**
     * @param list<Grant> $grants
     *
     * @throws InvalidGrantsException when the file cannot be read: it is never guessed at
     */
    public function add(array $grants): void
    {
        $this->update(static function (array $kept) use ($grants): array {
            foreach ($grants as $grant) {
                if (!array_any($kept, $grant->equals(...))) {
                    $kept[] = $grant;
                }
            }

            return $kept;
        });
    }

    /**
     * Each grant goes as it was given: revoking `ls` leaves `ls …`.
     *
     * @param list<Grant> $grants
     *
     * @throws InvalidGrantsException when the file cannot be read: it is never guessed at
     */
    public function revoke(array $grants): void
    {
        $this->update(static fn (array $kept): array => array_values(array_filter(
            $kept,
            static fn (Grant $grant): bool => !array_any($grants, $grant->equals(...)),
        )));
    }

    /**
     * @param \Closure(list<Grant>): list<Grant> $change
     *
     * @throws InvalidGrantsException
     */
    private function update(\Closure $change): void
    {
        $lock = $this->lockFactory->createLock('airlock-grants-'.hash('sha256', $this->file()));
        $lock->acquire(blocking: true);
        try {
            $kept = $change($this->all());
            $this->writePrivately(Yaml::dump(['grants' => array_map($this->normalize(...), $kept)], 3));
        } finally {
            $lock->release();
        }
    }

    /**
     * Whoever can change the file can let any command run without asking: it is written readable and writable by its
     * owner only, in directories nobody else can enter, and as a new file each time, so that one an earlier version
     * left open, and anything someone else opened then, is replaced rather than changed.
     */
    private function writePrivately(string $content): void
    {
        $file = $this->file();
        $directory = \dirname($file);
        foreach ([$this->configDirectory->path, $directory] as $private) {
            $this->filesystem->mkdir($private, 0o700);
            $this->filesystem->chmod($private, 0o700);
        }

        $temporary = $this->filesystem->tempnam($directory, '.grants-');
        try {
            $this->filesystem->chmod($temporary, 0o600);
            $this->filesystem->appendToFile($temporary, $content);
            $this->filesystem->rename($temporary, $file, true);
        } finally {
            $this->filesystem->remove($temporary);
        }
    }

    private function file(): string
    {
        return $this->configDirectory->forProject($this->projectDirectory->path).'/grants.yaml';
    }

    /**
     * @return array{command: list<string>, prefix: bool}
     */
    private function normalize(Grant $grant): array
    {
        return ['command' => $grant->words, 'prefix' => $grant->coversLongerCommands];
    }

    /**
     * @return list<Grant>
     */
    private function grantsIn(mixed $content, string $file): array
    {
        $entries = \is_array($content) && \array_key_exists('grants', $content) ? $content['grants'] : null;
        if (!\is_array($entries)) {
            throw new InvalidGrantsException(\sprintf('The grants file %s holds no "grants" list.', $file));
        }

        $grants = [];
        foreach ($entries as $entry) {
            $grants[] = $this->denormalize($entry, $file);
        }

        return $grants;
    }

    private function denormalize(mixed $entry, string $file): Grant
    {
        $invalid = new InvalidGrantsException(\sprintf('The grants file %s holds an entry that is not {command: [words], prefix: true|false}.', $file));
        $command = \is_array($entry) ? ($entry['command'] ?? null) : null;
        $prefix = \is_array($entry) ? ($entry['prefix'] ?? null) : null;
        if (!\is_array($command) || !array_is_list($command) || !\is_bool($prefix)) {
            throw $invalid;
        }

        $words = [];
        foreach ($command as $word) {
            if (!\is_string($word)) {
                throw $invalid;
            }
            $words[] = $word;
        }
        if ([] === $words) {
            throw $invalid;
        }

        return new Grant($words, $prefix);
    }
}
