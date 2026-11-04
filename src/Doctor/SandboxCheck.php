<?php

declare(strict_types=1);

namespace App\Doctor;

use App\Sandbox\NoSandbox;
use App\Sandbox\SandboxDetector;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 60)]
final readonly class SandboxCheck implements Check
{
    public function __construct(
        private SandboxDetector $detector,
    ) {
    }

    #[\Override]
    public function run(): Finding
    {
        $sandbox = $this->detector->detect();
        if ($sandbox instanceof NoSandbox) {
            return Finding::warning('Sandbox', \sprintf('None: %s. A command would run with all your permissions, so Airlock asks before every one.', rtrim($sandbox->reason, '.')));
        }

        return Finding::ok('Sandbox', $sandbox->describe());
    }
}
