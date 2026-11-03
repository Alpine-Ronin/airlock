<?php

declare(strict_types=1);

namespace App\Connect;

/**
 * One configuration file as it is and as it will be once Airlock has written it.
 */
final readonly class PlannedFile
{
    /**
     * @param string|null  $after   null when the file is removed
     * @param list<string> $changes what Airlock changes in it, in words
     * @param list<string> $left    shared settings disconnecting leaves as they are, with no record of the original
     */
    public function __construct(
        public string $path,
        public bool $exists,
        public string $before,
        public ?string $after,
        public array $changes,
        public array $left = [],
    ) {
    }

    public function isChanged(): bool
    {
        return $this->before !== $this->after;
    }

    public function isRemoved(): bool
    {
        return null === $this->after;
    }
}
