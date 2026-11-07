<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Audit\Outcome;
use App\Configuration\ConfigDirectory;
use App\Output\NotCheckedReason;
use App\Output\OutputCheck;
use App\Policy\Verdict;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

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

    public function testAnOutputCheckThatKeepsTimingOutIsAWarning(): void
    {
        $this->appendTimedOutOutputs(3);
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]), 'A warning leaves Airlock working: only a problem fails.');
        self::assertStringContainsString('Plain-words line: test/risk. Run airlock configure --check to calibrate them. The output check timed out on 3 of the last 3 outputs in this project', $this->display($tester));
    }

    public function testAnOutputCheckThatKeepsTimingOutIsAWarningEvenWithThePlainWordsLineOff(): void
    {
        $this->appendTimedOutOutputs(3);
        $_SERVER['AIRLOCK_RISK_MODEL'] = $_ENV['AIRLOCK_RISK_MODEL'] = '';
        $tester = $this->tester();

        $tester->execute([]);

        self::assertStringContainsString('The output check timed out on 3 of the last 3 outputs in this project', $this->display($tester));
    }

    public function testAnAuditDirectoryThatCannotBeEnteredLeavesTheTimeoutsUnknown(): void
    {
        $this->appendTimedOutOutputs(3);
        $this->filesystem->chmod(self::AIRLOCK, 0o000);
        $tester = $this->tester();

        try {
            $this->assertPermissionsAreEnforced(self::AIRLOCK.'/audit.jsonl');
            $tester->execute([]);
        } finally {
            $this->filesystem->chmod(self::AIRLOCK, 0o700);
        }

        self::assertStringContainsString('Whether the output check keeps timing out is unknown.', $this->display($tester), 'A log Airlock cannot reach is not a log without timeouts.');
    }

    public function testADirectoryAboveTheAuditLogThatCannotBeEnteredLeavesTheTimeoutsUnknown(): void
    {
        $this->appendTimedOutOutputs(3);
        $this->filesystem->chmod(self::HOME.'/.config', 0o000);
        $tester = $this->tester();

        try {
            $this->assertPermissionsAreEnforced(self::AIRLOCK);
            $tester->execute([]);
        } finally {
            $this->filesystem->chmod(self::HOME.'/.config', 0o700);
        }

        self::assertMatchesRegularExpression('/Airlock cannot read .*audit\.jsonl: .*\/\.config cannot be entered\. Whether the output check keeps timing out is unknown\./', $this->display($tester));
    }

    public function testAnAuditLogThatCannotBeReadDoesNotStopTheOtherChecks(): void
    {
        $this->filesystem->dumpFile(self::AIRLOCK.'/audit.jsonl', '');
        $this->filesystem->chmod(self::AIRLOCK.'/audit.jsonl', 0o000);
        $tester = $this->tester();

        try {
            $this->assertPermissionsAreEnforced(self::AIRLOCK.'/audit.jsonl');
            $tester->execute([]);
        } finally {
            $this->filesystem->chmod(self::AIRLOCK.'/audit.jsonl', 0o600);
        }

        self::assertMatchesRegularExpression('/Model checks: .* Airlock cannot read .*audit\.jsonl: Failed to open stream: Permission denied\. Whether the output check keeps timing out is unknown\./', $this->display($tester));
        self::assertStringContainsString('Clients:', $this->display($tester), 'The other checks still run.');
    }

    public function testAProjectWithoutGrantsIsOfferedThePresetItsFilesSuggest(): void
    {
        $this->filesystem->dumpFile(self::PROJECT.'/package.json', "{}\n");
        $tester = $this->tester();

        $tester->execute([]);

        self::assertStringContainsString('Grants: No command is allowed always in this project. To allow its usual commands (tests, linters, analysis) in one step: airlock grants --preset node.', $this->display($tester));
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
        self::assertStringContainsString(\sprintf("Others can open %s/models.yaml. Close them with: chmod -R go= '%s'", self::AIRLOCK, self::AIRLOCK), $this->display($tester));
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

    public function testADirectoryThatCannotBeReadIsAProblem(): void
    {
        $this->filesystem->mkdir(self::AIRLOCK.'/projects', 0o700);
        $this->filesystem->chmod(self::AIRLOCK, 0o700);
        $this->filesystem->chmod(self::AIRLOCK.'/projects', 0o000);
        $tester = $this->tester();

        try {
            $this->assertPermissionsAreEnforced(self::AIRLOCK.'/projects');
            $status = $tester->execute([]);
        } finally {
            $this->filesystem->chmod(self::AIRLOCK.'/projects', 0o700);
        }

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString(\sprintf("Airlock's files: Airlock cannot read all of %s", self::AIRLOCK), $this->display($tester));
        self::assertStringContainsString('Clients:', $this->display($tester), 'The other checks still run.');
    }

    public function testADirectoryThatCanBeListedButNotEnteredIsAProblem(): void
    {
        $this->filesystem->dumpFile(self::AIRLOCK.'/projects/demo/grants.yaml', "always: []\n");
        $this->filesystem->chmod(self::AIRLOCK, 0o700);
        $this->filesystem->chmod(self::AIRLOCK.'/projects', 0o600);
        $tester = $this->tester();

        try {
            $this->assertPermissionsAreEnforced(self::AIRLOCK.'/projects/demo');
            $status = $tester->execute([]);
        } finally {
            $this->filesystem->chmod(self::AIRLOCK.'/projects', 0o700);
        }

        self::assertSame(Command::FAILURE, $status, 'What cannot be looked at is not known to be private.');
        self::assertStringContainsString(\sprintf("Airlock's files: Airlock cannot read all of %s", self::AIRLOCK), $this->display($tester));
    }

    public function testAirlocksDirectoryThatCanBeListedButNotEnteredIsAProblem(): void
    {
        $this->filesystem->dumpFile(self::AIRLOCK.'/models.yaml', '');
        $this->filesystem->chmod(self::AIRLOCK, 0o600);
        $tester = $this->tester();

        try {
            $this->assertPermissionsAreEnforced(self::AIRLOCK.'/models.yaml');
            $status = $tester->execute([]);
        } finally {
            $this->filesystem->chmod(self::AIRLOCK, 0o700);
        }

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString(\sprintf("Airlock's files: Airlock cannot read all of %s", self::AIRLOCK), $this->display($tester));
    }

    public function testADirectoryAboveAirlocksThatCannotBeEnteredIsAProblem(): void
    {
        $this->filesystem->mkdir(self::AIRLOCK, 0o700);
        $this->filesystem->chmod(self::HOME.'/.config', 0o000);
        $tester = $this->tester();

        try {
            $this->assertPermissionsAreEnforced(self::AIRLOCK);
            $status = $tester->execute([]);
        } finally {
            $this->filesystem->chmod(self::HOME.'/.config', 0o700);
        }

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString(\sprintf("Airlock's files: Airlock cannot reach %s: %s cannot be entered.", self::AIRLOCK, self::HOME.'/.config'), $this->display($tester));
        self::assertStringNotContainsString('does not exist yet', $this->display($tester));
    }

    public function testAClientFileThatCannotBeReadLeavesThatClientOut(): void
    {
        $this->filesystem->dumpFile(self::PROJECT.'/.mcp.json', '{}');
        $this->filesystem->chmod(self::PROJECT.'/.mcp.json', 0o000);
        $tester = $this->tester();

        try {
            $this->assertPermissionsAreEnforced(self::PROJECT.'/.mcp.json');
            $status = $tester->execute([]);
        } finally {
            $this->filesystem->chmod(self::PROJECT.'/.mcp.json', 0o600);
        }

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        self::assertStringContainsString('Airlock is connected to none of', $this->display($tester));
    }

    public function testAConnectedClientIsNamed(): void
    {
        $this->connectClaudeCode();
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Clients: Connected to Claude Code (project)', $this->display($tester));
    }

    public function testAClientThatStartsAnotherAirlockIsNamed(): void
    {
        $this->connectClaudeCode();
        $servers = (string) file_get_contents(self::PROJECT.'/.mcp.json');
        $this->filesystem->dumpFile(self::PROJECT.'/.mcp.json', (string) preg_replace('/"command": "[^"]*"/', '"command": "/opt/old/airlock"', $servers));
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Clients: Claude Code (project) starts another Airlock than this one, such as an earlier install', $this->display($tester));
    }

    public function testAClientThatStillHasItsShellToolIsAProblem(): void
    {
        $this->connectClaudeCode();
        $this->filesystem->remove(self::PROJECT.'/.claude/settings.local.json');
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Claude Code (project) starts Airlock, but not all that connecting writes, such as its own shell tool off: the agent may run commands around Airlock', $this->display($tester));
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

    private function appendTimedOutOutputs(int $count): void
    {
        $log = new AuditLog(new ConfigDirectory(self::HOME, self::HOME.'/.config'), new LockFactory(new InMemoryStore()));
        for ($minute = 0; $minute < $count; ++$minute) {
            $log->append(new AuditEntry(new \DateTimeImmutable(\sprintf('2026-11-05T10:%02d:00+00:00', $minute)), (string) realpath(self::PROJECT), 'make test', Verdict::Plain, Outcome::RanOnce, outputCheck: OutputCheck::notChecked(NotCheckedReason::Timeout)));
        }
    }

    private function assertPermissionsAreEnforced(string $unreadable): void
    {
        self::assertIsNotReadable($unreadable, 'Run the tests as a user that file permissions apply to, not root: this test needs a path it cannot read.');
    }

    private function connectClaudeCode(): void
    {
        new CommandTester(new Application(self::bootKernel())->find('connect'))->execute(['client' => 'claude-code']);
        self::ensureKernelShutdown();
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
