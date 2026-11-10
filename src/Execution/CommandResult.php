<?php

declare(strict_types=1);

namespace App\Execution;

final readonly class CommandResult
{
    /**
     * @param int|null                $exitCode           null when the command was stopped before it exited
     * @param string                  $output             standard output and error, interleaved as they were written, secrets masked
     * @param int                     $maskedSecrets      how many secrets were masked in it
     * @param BlockedConnections|null $blockedConnections what the sandbox kept it from reaching; null for nothing
     */
    public function __construct(
        public ?int $exitCode,
        public string $output,
        public bool $hasTimedOut = false,
        public int $maskedSecrets = 0,
        private ?BlockedConnections $blockedConnections = null,
    ) {
    }

    public function blockedConnections(): BlockedConnections
    {
        return $this->blockedConnections ?? BlockedConnections::none();
    }

    public function withBlockedConnections(BlockedConnections $blockedConnections): self
    {
        return new self($this->exitCode, $this->output, $this->hasTimedOut, $this->maskedSecrets, $blockedConnections);
    }

    public function isSuccessful(): bool
    {
        return 0 === $this->exitCode;
    }
}
