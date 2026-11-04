<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class DoctorCommandTest extends KernelTestCase
{
    private const string ROOT = __DIR__.'/../../var/test-doctor';

    private const string HOME = self::ROOT.'/home';

    private const string AIRLOCK = self::HOME.'/.config/airlock';

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
        $_SERVER['AIRLOCK_SANDBOX'] = $_ENV['AIRLOCK_SANDBOX'] = 'off';
        unset($_SERVER['CODEX_HOME'], $_ENV['CODEX_HOME']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_ENV = $this->environment;
        $this->filesystem->remove(self::ROOT);
        parent::tearDown();
    }

    public function testWithoutASandboxItWarnsThatEveryCommandIsAsked(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]), 'A warning leaves Airlock working: only a problem fails.');
        self::assertStringContainsString('Sandbox: None: turned off with AIRLOCK_SANDBOX=off', $this->display($tester));
        self::assertStringContainsString('Airlock asks before every one', $this->display($tester));
    }

    public function testTheModelsTheServerWouldUseAreNamed(): void
    {
        $tester = $this->tester();

        $tester->execute([]);

        self::assertStringContainsString('Through https://models.example.test. Output check: test/output-check. Plain-words line: test/risk.', $this->display($tester));
        self::assertStringNotContainsString('test-key', $this->display($tester), 'The API key is never shown.');
    }

    public function testWithoutAModelApiTheChecksAreSaidToBeOff(): void
    {
        $_SERVER['AIRLOCK_AI_BASE_URL'] = $_ENV['AIRLOCK_AI_BASE_URL'] = '';
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Model checks: Off: no model API is configured', $this->display($tester));
    }

    public function testModelSettingsThatCannotBeReadAreAProblem(): void
    {
        $this->filesystem->dumpFile(self::AIRLOCK.'/models.yaml', "base_url: [\n");
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('The model checks are off until it is fixed', $this->display($tester));
    }

    public function testAPolicyThatCannotBeReadIsAProblem(): void
    {
        $this->filesystem->dumpFile(self::PROJECT.'/.airlock.yaml', "refuse: [\n");
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertMatchesRegularExpression('/Policy: The policy file .*\.airlock\.yaml is invalid.* Airlock runs no command until it is fixed/', $this->display($tester));
        self::assertStringContainsString('1 problem to fix', $this->display($tester));
    }

    public function testRefusalsAreCounted(): void
    {
        $this->filesystem->dumpFile(self::PROJECT.'/.airlock.yaml', "refuse:\n  - terraform apply\n  - kubectl delete\n");
        $tester = $this->tester();

        $tester->execute([]);

        self::assertStringContainsString('Your policy files refuse 2 commands', $this->display($tester));
    }

    public function testGrantsThatCannotBeReadAreAProblem(): void
    {
        $this->filesystem->dumpFile($this->projectDirectory().'/grants.yaml', "rm: -rf\n");
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertMatchesRegularExpression('/Grants: .* Airlock runs no command until it is fixed/', $this->display($tester));
    }

    public function testFilesOpenToOthersAreAProblem(): void
    {
        $this->filesystem->dumpFile(self::AIRLOCK.'/models.yaml', "base_url: ''\n");
        $this->filesystem->chmod(self::AIRLOCK, 0o700);
        $this->filesystem->chmod(self::AIRLOCK.'/models.yaml', 0o644);
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString(\sprintf('Others can open %s/models.yaml. Close them with: chmod -R go= %s', self::AIRLOCK, self::AIRLOCK), $this->display($tester));
    }

    public function testFilesOpenToTheirOwnerOnlyAreFine(): void
    {
        $this->filesystem->dumpFile(self::AIRLOCK.'/models.yaml', "base_url: ''\n");
        $this->filesystem->chmod(self::AIRLOCK, 0o700);
        $this->filesystem->chmod(self::AIRLOCK.'/models.yaml', 0o600);
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('is open to you only', $this->display($tester));
    }

    public function testAConnectedClientIsNamed(): void
    {
        new CommandTester(new Application(self::bootKernel())->find('connect'))->execute(['client' => 'claude-code']);
        self::ensureKernelShutdown();
        $tester = $this->tester();

        $tester->execute([]);

        self::assertStringContainsString('Clients: Connected to Claude Code (project)', $this->display($tester));
    }

    public function testWithoutAConnectedClientItSaysHowToConnectOne(): void
    {
        $tester = $this->tester();

        $tester->execute([]);

        self::assertStringContainsString('Airlock is connected to none of claude-code, codex, cursor, vscode, goose from this project: run airlock connect <client>', $this->display($tester));
    }

    public function testNothingIsWritten(): void
    {
        $tester = $this->tester();

        $tester->execute([]);

        self::assertDirectoryDoesNotExist(self::AIRLOCK, 'The doctor looks, and changes nothing.');
        self::assertSame([], array_values(array_diff((array) scandir(self::PROJECT), ['.', '..'])));
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new Application(self::bootKernel())->find('doctor'));
    }

    private function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function projectDirectory(): string
    {
        $project = (string) realpath(self::PROJECT);

        return \sprintf('%s/projects/project-%s', self::AIRLOCK, substr(hash('sha256', $project), 0, 12));
    }
}
