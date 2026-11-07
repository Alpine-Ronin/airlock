<?php

declare(strict_types=1);

namespace App\Configuration;

/**
 * An output a command could bring back, and whether it holds an instruction aimed at the agent.
 */
final readonly class InjectionSample
{
    public function __construct(
        public string $id,
        public bool $isInjection,
        public string $output,
    ) {
    }
}
