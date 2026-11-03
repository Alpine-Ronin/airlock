<?php

declare(strict_types=1);

namespace App\Connect;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Reads and writes clients' configuration files, and keeps each one as it was before Airlock first changed it, so
 * that disconnecting takes back what Airlock added and nothing the user had.
 */
final readonly class ClientConfigFiles
{
    public function __construct(
        private ConfigSyntaxes $syntaxes,
        private ConnectionOriginals $originals,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @throws UnsupportedConfigException when the file cannot be edited without risking what else it holds
     */
    public function plan(FileEdit $edit, Direction $direction): PlannedFile
    {
        $exists = file_exists($edit->path);
        $before = $exists ? $this->read($edit->path) : '';
        $syntax = $this->syntaxes->for($edit->format);
        $changes = array_map(static fn (ConfigChange $change): string => $change->describe($direction), $edit->changes);
        if (Direction::Connect === $direction) {
            return new PlannedFile($edit->path, $exists, $before, $syntax->connect($before, $edit->changes), $changes);
        }

        $original = $this->originals->of($edit->path);
        $left = [];
        if (null === $original) {
            foreach ($edit->changes as $change) {
                if ($change->isShared()) {
                    $left[] = $change->path()->dotted();
                }
            }
        }

        $after = $exists ? $syntax->disconnect($before, $edit->changes, $original) : $before;
        if ($exists && $this->holdsOnlyWhatConnectingLeft($edit, $syntax, $after)) {
            $after = null;
        }

        return new PlannedFile($edit->path, $exists, $before, $after, $changes, $left);
    }

    /**
     * A file connecting created, holding nothing but the empty structure disconnecting leaves behind: removing it puts
     * back what the user had. One they added to since, or one that existed even empty, is theirs and stays.
     */
    private function holdsOnlyWhatConnectingLeft(FileEdit $edit, ConfigSyntax $syntax, string $after): bool
    {
        if (!$this->originals->wasAbsent($edit->path)) {
            return false;
        }
        $leftByAFreshConnection = $syntax->disconnect($syntax->connect('', $edit->changes), $edit->changes, '');

        return $after === $leftByAFreshConnection;
    }

    /**
     * Writes the planned files, or none of them when one changed since it was planned, such as while the user was
     * asked to confirm.
     *
     * @param list<PlannedFile> $files
     *
     * @throws ConfigChangedException
     */
    public function writeAll(array $files, Direction $direction): void
    {
        foreach ($files as $file) {
            if (!$this->isAsPlanned($file)) {
                throw new ConfigChangedException(\sprintf('%s changed since Airlock read it', $file->path));
            }
        }
        foreach ($files as $file) {
            $this->write($file, $direction);
        }
    }

    private function write(PlannedFile $file, Direction $direction): void
    {
        if (null === $file->after) {
            $this->filesystem->remove($file->path);
        } elseif ($file->isChanged()) {
            if (Direction::Connect === $direction) {
                $this->originals->keep($file->path, $file->exists, $file->before, $this->missingDirectoriesFor($file->path));
            }
            $this->filesystem->dumpFile($file->path, $file->after);
        }
        if (Direction::Disconnect === $direction) {
            $this->disconnectRecord($file->path);
        }
    }

    /**
     * Forgetting the record forgets which directories connecting created: once the file is gone, whether disconnecting
     * removed it or the user did, this is the last chance to take them back.
     */
    private function disconnectRecord(string $path): void
    {
        clearstatcache(true, $path);
        if (!file_exists($path)) {
            $this->removeEmptyDirectoriesCreatedFor($path);
        }
        $this->originals->forget($path);
    }

    /**
     * @return list<string> the directories writing the file would create, deepest first
     */
    private function missingDirectoriesFor(string $path): array
    {
        $missing = [];
        $directory = \dirname($path);
        while (!is_dir($directory) && \dirname($directory) !== $directory) {
            $missing[] = $directory;
            $directory = \dirname($directory);
        }

        return $missing;
    }

    /**
     * Only what connecting created, and only while empty: a directory the user had, or has put something in since,
     * stays, and so do the ones above it.
     */
    private function removeEmptyDirectoriesCreatedFor(string $path): void
    {
        foreach ($this->originals->createdDirectories($path) as $directory) {
            if (!$this->removeIfEmpty($directory)) {
                return;
            }
        }
    }

    /**
     * rmdir() itself refuses a directory that is not empty, even one something reached after any check could have
     * run: nothing put there is lost. Its refusal is the answer, not an error, hence the warning it raises is caught.
     */
    private function removeIfEmpty(string $directory): bool
    {
        if (!is_dir($directory)) {
            return false;
        }

        set_error_handler(static fn (): bool => true);
        try {
            return rmdir($directory);
        } finally {
            restore_error_handler();
        }
    }

    private function isAsPlanned(PlannedFile $file): bool
    {
        clearstatcache(true, $file->path);
        if (!file_exists($file->path)) {
            return !$file->exists;
        }

        return $file->exists && is_file($file->path) && $this->read($file->path) === $file->before;
    }

    private function read(string $path): string
    {
        if (!is_file($path)) {
            throw new UnsupportedConfigException('it is not a file');
        }
        $content = file_get_contents($path);
        if (false === $content) {
            throw new \RuntimeException(\sprintf('Airlock cannot read %s.', $path));
        }

        return $content;
    }
}
