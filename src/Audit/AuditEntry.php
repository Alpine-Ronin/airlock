<?php

declare(strict_types=1);

namespace App\Audit;

use App\Output\OutputCheck;
use App\Policy\Verdict;

final readonly class AuditEntry
{
    /**
     * @param list<string> $reasons            why the policy did not find it plain
     * @param int|null     $exitCode           null when it did not run, or was stopped
     * @param int|null     $maskedSecrets      null when it did not run
     * @param list<string> $blockedConnections the hosts the sandbox kept it from, as host:port
     */
    public function __construct(
        public \DateTimeImmutable $at,
        public string $project,
        public string $command,
        public Verdict $verdict,
        public Outcome $outcome,
        public array $reasons = [],
        public ?int $exitCode = null,
        public bool $hasTimedOut = false,
        public ?string $detail = null,
        public ?OutputCheck $outputCheck = null,
        public ?int $maskedSecrets = null,
        public array $blockedConnections = [],
    ) {
    }

    /**
     * @return array<string, scalar|list<string>|null>
     */
    public function toArray(): array
    {
        return [
            'at' => $this->at->format(\DATE_ATOM),
            'project' => $this->project,
            'command' => $this->command,
            'verdict' => $this->verdict->value,
            'outcome' => $this->outcome->value,
            'reasons' => $this->reasons,
            'exit_code' => $this->exitCode,
            'timed_out' => $this->hasTimedOut,
            'detail' => $this->detail,
            'output_check' => $this->outputCheck?->verdict->value,
            'output_check_reason' => $this->outputCheck?->reason?->value,
            'masked_secrets' => $this->maskedSecrets,
            'blocked_connections' => $this->blockedConnections,
        ];
    }
}
