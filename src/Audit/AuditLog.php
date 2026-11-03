<?php

declare(strict_types=1);

namespace App\Audit;

use App\Configuration\ConfigDirectory;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

/**
 * Every decision, appended as one JSON line to a file in Airlock's directory, where no command can change it.
 */
final readonly class AuditLog
{
    private const int CHUNK = 64 * 1024;

    public function __construct(
        private ConfigDirectory $configDirectory,
        private LockFactory $lockFactory,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function append(AuditEntry $entry): void
    {
        $lock = $this->lockFactory->createLock('airlock-audit');
        $lock->acquire(blocking: true);
        try {
            $this->closeToOthers();
            $this->filesystem->appendToFile($this->file(), json_encode($entry->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)."\n");
        } finally {
            $lock->release();
        }
    }

    /**
     * The last entries for one project, oldest first, as the JSON lines they were written as.
     *
     * @return list<string>
     */
    public function lastEntriesFor(string $project, int $count): array
    {
        $file = $this->file();
        if ($count <= 0 || !is_file($file)) {
            return [];
        }

        $newestFirst = [];
        foreach ($this->linesNewestFirst($file) as $line) {
            $entry = json_decode($line, true);
            if (!\is_array($entry) || ($entry['project'] ?? null) !== $project) {
                continue;
            }
            $newestFirst[] = $line;
            if (\count($newestFirst) === $count) {
                break;
            }
        }

        return array_reverse($newestFirst);
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
        $handle = fopen($file, 'r');
        if (false === $handle) {
            throw new \RuntimeException(\sprintf('Airlock cannot read %s.', $file));
        }

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
                    throw new \RuntimeException(\sprintf('Airlock cannot read %s.', $file));
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

    private function file(): string
    {
        return $this->configDirectory->path.'/audit.jsonl';
    }
}
