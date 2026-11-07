<?php

declare(strict_types=1);

namespace App\Grant;

/**
 * A project file that decides what a command does, by its path in the project, with the SHA-256 of its content. An
 * empty hash is a file that could not be read: it never matches, so the command is asked about.
 */
final readonly class Source
{
    public function __construct(
        public string $path,
        public string $sha256,
    ) {
    }

    public function matches(self $other): bool
    {
        return '' !== $this->sha256 && $this->path === $other->path && $this->sha256 === $other->sha256;
    }
}
