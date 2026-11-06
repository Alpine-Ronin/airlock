<?php

declare(strict_types=1);

namespace App\Audit;

use App\Output\NotCheckedReason;
use App\Output\OutputVerdict;

/**
 * Reads from the audit log whether the output check keeps timing out in a project, for `doctor` and `log` to say so.
 */
final readonly class OutputCheckTimeouts
{
    private const int RECENT_DECISIONS = 20;

    public function __construct(
        private AuditLog $auditLog,
    ) {
    }

    /**
     * @throws UnreadableAuditLogException when a log cannot be read
     */
    public function recent(string $project): RecentOutputChecks
    {
        $outputs = 0;
        $timedOut = 0;
        foreach ($this->auditLog->lastEntriesFor($project, self::RECENT_DECISIONS) as $line) {
            $entry = json_decode($line, true);
            if (!\is_array($entry) || !\is_string($entry['output_check'] ?? null)) {
                continue;
            }
            ++$outputs;
            $isNotChecked = OutputVerdict::NotChecked->value === $entry['output_check'];
            if ($isNotChecked && NotCheckedReason::Timeout->value === ($entry['output_check_reason'] ?? null)) {
                ++$timedOut;
            }
        }

        return new RecentOutputChecks($outputs, $timedOut);
    }
}
