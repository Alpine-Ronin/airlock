<?php

declare(strict_types=1);

namespace App\Execution;

final readonly class CommandResult
{
    /**
     * @param int|null     $exitCode           null when the command was stopped before it exited
     * @param string       $output             standard output and error, interleaved as they were written, secrets masked
     * @param int          $maskedSecrets      how many secrets were masked in it
     * @param list<string> $blockedConnections the hosts it tried to reach that the sandbox kept it from, as host:port
     */
    public function __construct(
        public ?int $exitCode,
        public string $output,
        public bool $hasTimedOut = false,
        public int $maskedSecrets = 0,
        public array $blockedConnections = [],
    ) {
    }

    /**
     * @param list<string> $blockedConnections
     */
    public function withBlockedConnections(array $blockedConnections): self
    {
        return new self($this->exitCode, $this->output, $this->hasTimedOut, $this->maskedSecrets, $blockedConnections);
    }

    public function isSuccessful(): bool
    {
        return 0 === $this->exitCode;
    }
}
