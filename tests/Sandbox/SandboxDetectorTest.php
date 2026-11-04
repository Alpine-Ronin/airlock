<?php

declare(strict_types=1);

namespace App\Tests\Sandbox;

use App\Configuration\ConfigDirectory;
use App\Execution\ProjectDirectory;
use App\Sandbox\NoSandbox;
use App\Sandbox\Sandbox;
use App\Sandbox\SandboxDetector;
use App\Sandbox\SandboxMode;
use App\Sandbox\SandboxPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;

final class SandboxDetectorTest extends TestCase
{
    private const string HOME = __DIR__.'/../../var/test-sandbox-detector';

    public function testTurnedOffThereIsNoSandboxAndItSaysWhy(): void
    {
        $sandbox = $this->detect(SandboxMode::Off, 'Linux');

        self::assertInstanceOf(NoSandbox::class, $sandbox);
        self::assertStringContainsString('turned off with AIRLOCK_SANDBOX=off', $sandbox->describe());
        self::assertSame(['bash', '-c', 'ls'], $sandbox->confine(['bash', '-c', 'ls'], '/project'));
    }

    public function testOnASystemWithoutAKnownSandboxThereIsNoneAndItSaysWhy(): void
    {
        $sandbox = $this->detect(SandboxMode::Auto, 'Windows');

        self::assertInstanceOf(NoSandbox::class, $sandbox);
        self::assertStringContainsString('no sandbox is known on Windows', $sandbox->describe());
    }

    public function testWithoutBubblewrapInstalledThereIsNoneAndItSaysHowToGetOne(): void
    {
        $sandbox = new SandboxDetector(new SandboxPaths('/nonexistent', '/nonexistent', new ConfigDirectory('/nonexistent')), new ProjectDirectory(sys_get_temp_dir()), SandboxMode::Auto, new NoExecutables(), 'Linux')->detect();

        self::assertInstanceOf(NoSandbox::class, $sandbox);
        self::assertStringContainsString('bubblewrap is not installed', $sandbox->describe());
    }

    #[DataProvider('projectsHoldingTheHome')]
    public function testAProjectHoldingTheUsersKeysHasNoSandboxSoEveryCommandIsAsked(string $project): void
    {
        $home = $this->home();

        $sandbox = new SandboxDetector(new SandboxPaths($home, '/nonexistent', new ConfigDirectory($home)), new ProjectDirectory($home.'/'.$project), SandboxMode::Auto, new ExecutableFinder(), 'Linux')->detect();

        self::assertInstanceOf(NoSandbox::class, $sandbox, 'A command could create a missing ~/.ssh/config, whose ProxyCommand ssh runs later as the user.');
        self::assertStringContainsString(\sprintf('the project overlaps %s/', $home), $sandbox->describe());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function projectsHoldingTheHome(): iterable
    {
        yield 'the home itself, without a .ssh yet' => ['.'];
        yield 'a directory above the home' => ['..'];
    }

    public function testAProjectHoldingAirlockItselfHasNoSandbox(): void
    {
        $home = $this->home();
        new Filesystem()->mkdir($home.'/work/airlock');
        $airlock = $home.'/work/airlock';

        $sandbox = new SandboxDetector(new SandboxPaths($home, $airlock, new ConfigDirectory($home)), new ProjectDirectory($home.'/work'), SandboxMode::Auto, new ExecutableFinder(), 'Linux')->detect();

        self::assertInstanceOf(NoSandbox::class, $sandbox, 'A command could change Airlock\'s code, or create the .env.local it loads, and the next server would run it.');
        self::assertStringContainsString(\sprintf('the project overlaps %s', $airlock), $sandbox->describe());
    }

    public function testAProjectInsideAKeyStoreHasNoSandbox(): void
    {
        $home = $this->home();
        new Filesystem()->mkdir($home.'/.ssh/work');

        $sandbox = new SandboxDetector(new SandboxPaths($home, '/nonexistent', new ConfigDirectory($home)), new ProjectDirectory($home.'/.ssh/work'), SandboxMode::Auto, new ExecutableFinder(), 'Linux')->detect();

        self::assertInstanceOf(NoSandbox::class, $sandbox, 'Hiding ~/.ssh would hide the project from its own commands.');
        self::assertStringContainsString(\sprintf('the project overlaps %s/.ssh', $home), $sandbox->describe());
    }

    public function testAProjectInsideTheHomeKeepsItsSandbox(): void
    {
        $home = $this->home();
        new Filesystem()->mkdir([$home.'/.ssh', $home.'/work']);

        $sandbox = new SandboxDetector(new SandboxPaths($home, '/nonexistent', new ConfigDirectory($home)), new ProjectDirectory($home.'/work'), SandboxMode::Auto, new NoExecutables(), 'Linux')->detect();

        self::assertStringContainsString('bubblewrap is not installed', $sandbox->describe(), 'Only a project that holds the keys loses the sandbox.');
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove(self::HOME);
    }

    private function home(): string
    {
        new Filesystem()->mkdir(self::HOME.'/home');

        return (string) realpath(self::HOME.'/home');
    }

    private function detect(SandboxMode $mode, string $operatingSystemFamily): Sandbox
    {
        return new SandboxDetector(new SandboxPaths('/nonexistent', '/nonexistent', new ConfigDirectory('/nonexistent')), new ProjectDirectory(sys_get_temp_dir()), $mode, new ExecutableFinder(), $operatingSystemFamily)->detect();
    }
}
