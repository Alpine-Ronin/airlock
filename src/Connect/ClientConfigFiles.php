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
                $this->originals->keep($file->path, $file->exists, $file->before);
            }
            $this->filesystem->dumpFile($file->path, $file->after);
        }
        if (Direction::Disconnect === $direction) {
            $this->originals->forget($file->path);
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
