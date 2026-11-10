<?php

declare(strict_types=1);

namespace App\Network;

final class ProxyRefusedException extends \RuntimeException
{
    public function __construct(
        public readonly ProxyTarget $target,
        public readonly ProxyDecision $decision,
    ) {
        parent::__construct($decision->explain($target));
    }
}
