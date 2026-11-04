<?php

declare(strict_types=1);

namespace App\Doctor;

/**
 * What one check found, in a sentence or two the user can act on.
 */
final readonly class Finding
{
    private function __construct(
        public string $topic,
        public Health $health,
        public string $text,
    ) {
    }

    public static function ok(string $topic, string $text): self
    {
        return new self($topic, Health::Ok, $text);
    }

    public static function warning(string $topic, string $text): self
    {
        return new self($topic, Health::Warning, $text);
    }

    public static function problem(string $topic, string $text): self
    {
        return new self($topic, Health::Problem, $text);
    }
}
