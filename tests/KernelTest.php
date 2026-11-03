<?php

declare(strict_types=1);

namespace App\Tests;

use App\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class KernelTest extends TestCase
{
    private const string CACHE = __DIR__.'/../var/test-kernel';

    /** @var array<mixed> */
    private array $server;

    #[\Override]
    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $_SERVER['APP_CACHE_DIR'] = self::CACHE;
        new Filesystem()->remove(self::CACHE);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        new Filesystem()->remove(self::CACHE);
    }

    public function testAnEnvironmentTheKernelDoesNotAllowIsRefused(): void
    {
        $kernel = new Kernel('staging', true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The environment "staging" is not registered as allowed');

        $kernel->boot();
    }
}
