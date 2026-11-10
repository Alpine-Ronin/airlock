<?php

declare(strict_types=1);

namespace App\Tests\Sandbox;

use App\Sandbox\NetworkAccess;
use App\Sandbox\SandboxMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SandboxModeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, NetworkAccess}>
     */
    public static function networks(): iterable
    {
        yield 'auto, the default, keeps commands to the registries' => ['auto', NetworkAccess::Registries];
        yield 'registries is another name for auto' => ['registries', NetworkAccess::Registries];
        yield 'open lets commands reach any host' => ['open', NetworkAccess::Open];
        yield 'strict cuts the network off' => ['strict', NetworkAccess::None];
        yield 'off leaves everything open' => ['off', NetworkAccess::Open];
    }

    #[DataProvider('networks')]
    public function testEachModeSaysWhatCommandsReach(string $mode, NetworkAccess $network): void
    {
        self::assertSame($network, SandboxMode::from($mode)->network());
    }
}
