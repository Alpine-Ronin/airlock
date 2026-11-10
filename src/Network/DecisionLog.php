<?php

declare(strict_types=1);

namespace App\Network;

interface DecisionLog
{
    public function record(ProxyTarget $target, ProxyDecision $decision): void;
}
