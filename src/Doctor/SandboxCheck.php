<?php

declare(strict_types=1);

namespace App\Doctor;

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
        return SandboxFinding::of($this->detector->detect());
    }
}
