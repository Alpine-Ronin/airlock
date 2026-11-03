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
        if (!is_file($file)) {
            return [];
        }

        $content = rtrim($this->filesystem->readFile($file), "\n");
        if ('' === $content) {
            return [];
        }

        $entries = [];
        foreach (explode("\n", $content) as $line) {
            $entry = json_decode($line, true);
            if (\is_array($entry) && ($entry['project'] ?? null) === $project) {
                $entries[] = $line;
            }
        }

        return \array_slice($entries, -$count);
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
            $this->filesystem->chmod($copy, 0o600);
            $this->filesystem->appendToFile($copy, $this->filesystem->readFile($file));
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
