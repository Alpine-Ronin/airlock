<?php

declare(strict_types=1);

namespace App\Tests;

use App\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class KernelTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove(__DIR__.'/../var/cache/staging');
    }

    public function testAnEnvironmentTheKernelDoesNotAllowIsRefused(): void
    {
        $kernel = new Kernel('staging', true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The environment "staging" is not registered as allowed');

        $kernel->boot();
    }
}
