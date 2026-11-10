<?php

declare(strict_types=1);

namespace App\Tests\Network;

use App\Network\DecisionLog;
use App\Network\ProxyDecision;
use App\Network\ProxyTarget;

final class RecordedDecisions implements DecisionLog
{
    /** @var list<string> */
    public array $lines = [];

    #[\Override]
    public function record(ProxyTarget $target, ProxyDecision $decision): void
    {
        $this->lines[] = \sprintf('%s %s', $target->authority(), $decision->value);
    }
}
