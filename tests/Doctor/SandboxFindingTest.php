<?php

declare(strict_types=1);

namespace App\Tests\Doctor;

use App\Configuration\ConfigDirectory;
use App\Doctor\Health;
use App\Doctor\SandboxFinding;
use App\Sandbox\Bubblewrap;
use App\Sandbox\NoSandbox;
use App\Sandbox\SandboxExec;
use App\Sandbox\SandboxPaths;
use PHPUnit\Framework\TestCase;

final class SandboxFindingTest extends TestCase
{
    public function testASandboxThatLeavesTheNetworkOpenIsAWarning(): void
    {
        $finding = SandboxFinding::of(new Bubblewrap($this->paths(), cutsNetwork: false));

        self::assertSame(Health::Warning, $finding->health);
        self::assertStringStartsWith('Sandbox (bubblewrap):', $finding->text);
        self::assertStringContainsString('Commands can reach the network', $finding->text);
        self::assertStringContainsString('AIRLOCK_SANDBOX=strict', $finding->text);
    }

    public function testASandboxWithoutNetworkIsFine(): void
    {
        $finding = SandboxFinding::of(new SandboxExec($this->paths(), cutsNetwork: true));

        self::assertSame(Health::Ok, $finding->health);
        self::assertStringContainsString('no network', $finding->text);
    }

    public function testNoSandboxIsAWarningThatSaysWhy(): void
    {
        $finding = SandboxFinding::of(new NoSandbox('turned off with AIRLOCK_SANDBOX=off'));

        self::assertSame(Health::Warning, $finding->health);
        self::assertSame('None: turned off with AIRLOCK_SANDBOX=off. A command would run with all your permissions, so Airlock asks before every one.', $finding->text);
    }

    private function paths(): SandboxPaths
    {
        return new SandboxPaths('/home/user', '/opt/airlock', new ConfigDirectory('/home/user'), '/run/user/1000');
    }
}
