<?php

declare(strict_types=1);

namespace App\Execution;

/**
 * The connections the sandbox kept a command from, as host:port: how many, and the first ones by name. The command
 * chose the names, so only a few go on: enough to say what it tried, too few to crowd its output out of the injection
 * check, which reads the end of what the agent gets.
 */
final readonly class BlockedConnections
{
    public const int MOST_NAMED = 20;

    /**
     * @param list<string> $named the first ones, at most MOST_NAMED
     * @param int<0, max>  $count
     */
    private function __construct(
        public array $named,
        public int $count,
    ) {
    }

    public static function none(): self
    {
        return new self([], 0);
    }

    /**
     * @param list<string> $blocked each once, in the order they were tried
     */
    public static function of(array $blocked): self
    {
        return new self(\array_slice($blocked, 0, self::MOST_NAMED), \count($blocked));
    }

    public function isEmpty(): bool
    {
        return 0 === $this->count;
    }
}
