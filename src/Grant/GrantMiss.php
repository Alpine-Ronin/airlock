<?php

declare(strict_types=1);

namespace App\Grant;

/**
 * Why grants that cover a command's words do not let it run: the sentence the form opens with.
 */
final readonly class GrantMiss
{
    private function __construct(
        public string $reason,
    ) {
    }

    /**
     * @param non-empty-list<string> $paths
     */
    public static function changedSources(array $paths): self
    {
        $files = implode(', ', array_map(static fn (string $path): string => json_encode($path, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), $paths));

        return new self(\sprintf('Asked again: what it runs depends on %s, which changed since it was allowed, or was not checked then.', $files));
    }

    public static function changeableBeforeRead(): self
    {
        return new self('Asked again: a part of this line can change the files another part runs before it reads them, so no grant covers it.');
    }
}
