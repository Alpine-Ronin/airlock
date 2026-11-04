<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Configuration\ConfigDirectory;
use App\Execution\ProjectDirectory;
use App\Grant\Grant;
use App\Grant\ProjectGrants;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class GrantsCommandTest extends KernelTestCase
{
    private const string ROOT = __DIR__.'/../../var/test-grants-command';

    private const string HOME = self::ROOT.'/home';

    private const string PROJECT = self::ROOT.'/project';

    private Filesystem $filesystem;

    /** @var array<mixed> */
    private array $server;

    /** @var array<mixed> */
    private array $environment;

    #[\Override]
    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->filesystem->remove(self::ROOT);
        $this->filesystem->mkdir([self::HOME, self::PROJECT]);
        $this->server = $_SERVER;
        $this->environment = $_ENV;
        $_SERVER['HOME'] = $_ENV['HOME'] = self::HOME;
        $_SERVER['AIRLOCK_PROJECT_DIR'] = $_ENV['AIRLOCK_PROJECT_DIR'] = self::PROJECT;
        $_SERVER['XDG_CONFIG_HOME'] = $_ENV['XDG_CONFIG_HOME'] = self::HOME.'/.config';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_ENV = $this->environment;
        $this->filesystem->remove(self::ROOT);
        parent::tearDown();
    }

    public function testAProjectWithoutGrantsIsSaidToHaveNone(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No command is allowed always in this project', $tester->getDisplay());
    }

    public function testTheGrantsAreListedByNumber(): void
    {
        $this->grants()->add([new Grant(['make', 'test'], true), new Grant(['ls', '-la'], false)]);
        $tester = $this->tester();

        $tester->execute([]);

        self::assertMatchesRegularExpression('/1\s+make test …/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/2\s+ls -la/', $tester->getDisplay());
        self::assertStringContainsString('--revoke', $tester->getDisplay());
    }

    public function testARevokedGrantIsGoneAndTheOthersStay(): void
    {
        $this->grants()->add([new Grant(['make', 'test'], true), new Grant(['ls', '-la'], false)]);
        $tester = $this->tester();
        $tester->setInputs(['yes']);

        $status = $tester->execute(['--revoke' => ['1']]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertEquals([new Grant(['ls', '-la'], false)], $this->grants()->all());
        self::assertStringContainsString('Airlock asks about these commands again', $tester->getDisplay());
    }

    public function testSeveralGrantsAreRevokedAtOnce(): void
    {
        $this->grants()->add([new Grant(['make', 'test'], true), new Grant(['ls', '-la'], false), new Grant(['pwd'], false)]);
        $tester = $this->tester();
        $tester->setInputs(['yes']);

        $tester->execute(['--revoke' => ['3', '1']]);

        self::assertEquals([new Grant(['ls', '-la'], false)], $this->grants()->all());
    }

    public function testANumberNotListedRevokesNothing(): void
    {
        $this->grants()->add([new Grant(['ls'], false)]);
        $tester = $this->tester();

        $status = $tester->execute(['--revoke' => ['1', '2']]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('No grant is numbered 2', $tester->getDisplay());
        self::assertCount(1, $this->grants()->all());
    }

    public function testAnAnswerOfNoRevokesNothing(): void
    {
        $this->grants()->add([new Grant(['ls'], false)]);
        $tester = $this->tester();
        $tester->setInputs(['no']);

        $tester->execute(['--revoke' => ['1']]);

        self::assertCount(1, $this->grants()->all());
    }

    public function testAGrantsFileThatCannotBeReadIsReported(): void
    {
        $this->filesystem->dumpFile($this->configDirectory()->forProject((string) realpath(self::PROJECT)).'/grants.yaml', "grants: [\n");
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('is not valid YAML', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new Application(self::bootKernel())->find('grants'));
    }

    private function grants(): ProjectGrants
    {
        return new ProjectGrants($this->configDirectory(), new ProjectDirectory(self::PROJECT), new LockFactory(new InMemoryStore()));
    }

    private function configDirectory(): ConfigDirectory
    {
        return new ConfigDirectory(self::HOME, self::HOME.'/.config');
    }
}
