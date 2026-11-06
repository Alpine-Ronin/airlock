<?php

declare(strict_types=1);

namespace App\Audit;

use App\Configuration\ConfigDirectory;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockException;
use Symfony\Component\Lock\LockFactory;

/**
 * Every decision, appended as one JSON line to a file in Airlock's directory, where no command can change it. A full
 * log is moved aside, to `audit.1.jsonl`, the one before to `audit.2.jsonl`, and so on: the oldest beyond those kept
 * goes, so the log never fills the disk.
 */
final readonly class AuditLog
{
    /** About 20,000 decisions. */
    public const int ROTATION_SIZE = 10 * 1024 * 1024;

    public const int KEPT_FILES = 5;

    private const int CHUNK = 64 * 1024;

    /**
     * @param positive-int $rotationSize the size in bytes from which the log is moved aside
     * @param positive-int $keptFiles    how many logs moved aside are kept
     */
    public function __construct(
        private ConfigDirectory $configDirectory,
        private LockFactory $lockFactory,
        private Filesystem $filesystem = new Filesystem(),
        private int $rotationSize = self::ROTATION_SIZE,
        private int $keptFiles = self::KEPT_FILES,
    ) {
    }

    public function append(AuditEntry $entry): void
    {
        $lock = $this->lockFactory->createLock('airlock-audit');
        $lock->acquire(blocking: true);
        try {
            $this->closeToOthers();
            if ($this->isFull()) {
                $this->moveAside();
                $this->closeToOthers();
            }
            $this->filesystem->appendToFile($this->file(), json_encode($entry->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)."\n");
        } finally {
            $lock->release();
        }
    }

    /**
     * The last entries for one project, oldest first, as the JSON lines they were written as.
     *
     * @return list<string>
     *
     * @throws UnreadableAuditLogException when a log cannot be read
     * @throws LockException               when the log's lock cannot be taken
     */
    public function lastEntriesFor(string $project, int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        // Held while reading, so that a log moved aside meanwhile is not read twice or skipped.
        $lock = $this->lockFactory->createLock('airlock-audit');
        $lock->acquire(blocking: true);
        try {
            return $this->readLastEntriesFor($project, $count);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param positive-int $count
     *
     * @return list<string>
     */
    private function readLastEntriesFor(string $project, int $count): array
    {
        $newestFirst = [];
        foreach ($this->filesNewestFirst() as $file) {
            foreach ($this->linesNewestFirst($file) as $line) {
                $entry = json_decode($line, true);
                if (!\is_array($entry) || ($entry['project'] ?? null) !== $project) {
                    continue;
                }
                $newestFirst[] = $line;
                if (\count($newestFirst) === $count) {
                    return array_reverse($newestFirst);
                }
            }
        }

        return array_reverse($newestFirst);
    }

    /**
     * @return list<string> the log, then the logs moved aside, those that exist
     *
     * @throws UnreadableAuditLogException when Airlock's directory, or one above it, cannot be entered: its logs would
     *                                     look missing
     */
    private function filesNewestFirst(): array
    {
        $reachable = $this->nearestExistingDirectory($this->configDirectory->path);
        if (!is_executable($reachable)) {
            throw new UnreadableAuditLogException(\sprintf('Airlock cannot read %s: %s cannot be entered.', $this->file(), $reachable));
        }
        $files = [$this->file()];
        for ($number = 1; $number <= $this->keptFiles; ++$number) {
            $files[] = $this->movedAside($number);
        }

        return array_values(array_filter($files, is_file(...)));
    }

    /**
     * The directory itself, or the closest one above it that is seen to exist: below a directory that cannot be
     * entered, nothing is seen to exist.
     */
    private function nearestExistingDirectory(string $directory): string
    {
        $path = $directory;
        while (!is_dir($path) && \dirname($path) !== $path) {
            $path = \dirname($path);
        }

        return $path;
    }

    /**
     * The log's lines from its end, read a chunk at a time: the log only grows, and holding all of it in memory
     * would fail once it outgrows the memory limit. The pieces of a line longer than a chunk are joined once it is
     * complete, not at every chunk, which would copy a long line over and over.
     *
     * @return \Generator<int, string>
     */
    private function linesNewestFirst(string $file): \Generator
    {
        $handle = $this->openToRead($file);

        try {
            fseek($handle, 0, \SEEK_END);
            $position = (int) ftell($handle);
            $unfinishedLine = [];
            while ($position > 0) {
                $length = min(self::CHUNK, $position);
                $position -= $length;
                fseek($handle, $position);
                $chunk = fread($handle, $length);
                if (false === $chunk) {
                    throw new UnreadableAuditLogException(\sprintf('Airlock cannot read %s.', $file));
                }

                $parts = explode("\n", $chunk);
                $unfinishedLine[] = array_pop($parts);
                if ([] === $parts) {
                    continue;
                }

                yield from $this->unlessEmpty(implode('', array_reverse($unfinishedLine)));
                $unfinishedLine = [array_shift($parts)];
                foreach (array_reverse($parts) as $line) {
                    yield from $this->unlessEmpty($line);
                }
            }
            yield from $this->unlessEmpty(implode('', array_reverse($unfinishedLine)));
        } finally {
            fclose($handle);
        }
    }

    /**
     * fopen() says why a file cannot be opened only in a warning, caught here: the exception says it instead.
     *
     * @return resource
     *
     * @throws UnreadableAuditLogException
     */
    private function openToRead(string $file)
    {
        $reason = 'it cannot be opened';
        set_error_handler(static function (int $level, string $message) use (&$reason): bool {
            $reason = (string) preg_replace('/^fopen\(.*?\): /', '', $message);

            return true;
        });
        try {
            $handle = fopen($file, 'r');
        } finally {
            restore_error_handler();
        }
        if (false === $handle) {
            throw new UnreadableAuditLogException(\sprintf('Airlock cannot read %s: %s.', $file, $reason));
        }

        return $handle;
    }

    /**
     * @return \Generator<int, string>
     */
    private function unlessEmpty(string $line): \Generator
    {
        if ('' !== $line) {
            yield $line;
        }
    }

    /**
     * The log names every command run in every project: nobody else may open it, not even while it is being made,
     * which its directory ensures. A log an earlier version left open is replaced rather than closed: chmod does not
     * take back a file someone else opened in the meantime.
     */
    private function closeToOthers(): void
    {
        $this->filesystem->mkdir($this->configDirectory->path, 0o700);
        $this->filesystem->chmod($this->configDirectory->path, 0o700);

        $file = $this->file();
        clearstatcache(true, $file);
        if (!is_file($file)) {
            $this->filesystem->dumpFile($file, '');
            $this->filesystem->chmod($file, 0o600);

            return;
        }
        $mode = fileperms($file);
        if (false === $mode || 0o600 !== ($mode & 0o777)) {
            $this->replaceWithPrivateCopy($file);
        }
    }

    private function replaceWithPrivateCopy(string $file): void
    {
        $copy = $this->filesystem->tempnam(\dirname($file), '.audit-');
        try {
            $this->filesystem->copy($file, $copy, overwriteNewerFiles: true);
            $this->filesystem->chmod($copy, 0o600);
            $this->filesystem->rename($copy, $file, true);
        } finally {
            $this->filesystem->remove($copy);
        }
    }

    private function isFull(): bool
    {
        clearstatcache(true, $this->file());
        $size = filesize($this->file());

        return false !== $size && $size >= $this->rotationSize;
    }

    /**
     * Renames keep each file private, and keep the entries of one whole: a reader never sees half a move.
     */
    private function moveAside(): void
    {
        $this->filesystem->remove($this->movedAside($this->keptFiles));
        for ($number = $this->keptFiles - 1; $number >= 1; --$number) {
            if (is_file($this->movedAside($number))) {
                $this->filesystem->rename($this->movedAside($number), $this->movedAside($number + 1), true);
            }
        }
        $this->filesystem->rename($this->file(), $this->movedAside(1), true);
    }

    private function file(): string
    {
        return $this->configDirectory->path.'/audit.jsonl';
    }

    private function movedAside(int $number): string
    {
        return \sprintf('%s/audit.%d.jsonl', $this->configDirectory->path, $number);
    }
}
