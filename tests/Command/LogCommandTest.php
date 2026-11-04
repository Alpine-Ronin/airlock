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

final class LogCommandTest extends KernelTestCase
{
    private const string ROOT = __DIR__.'/../../var/test-log-command';

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

    public function testAProjectWithoutDecisionsIsSaidToHaveNone(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('no decision in this project yet', $tester->getDisplay());
    }

    public function testEachDecisionIsShownInPlainWordsOldestFirst(): void
    {
        $this->append('cat README.md', Verdict::Plain, Outcome::RanOnce, OutputCheck::injection(), '10:00');
        $this->append('rm -rf build', Verdict::AskEveryTime, Outcome::RefusedByUser, null, '10:01');
        $this->append('make test', Verdict::Plain, Outcome::RanAndGrantedForProject, OutputCheck::notChecked(NotCheckedReason::Timeout), '10:02');
        $tester = $this->tester();

        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/2026-11-05 10:00\s+cat README.md\s+allowed once\s+injection flagged/', $display);
        self::assertMatchesRegularExpression('/2026-11-05 10:01\s+rm -rf build\s+refused by you/', $display);
        self::assertMatchesRegularExpression('/2026-11-05 10:02\s+make test\s+allowed always\s+not checked \(timeout\)/', $display);
        self::assertLessThan(strpos($display, 'rm -rf build'), strpos($display, 'cat README.md'));
    }

    public function testOnlyTheLastDecisionsAskedForAreShown(): void
    {
        $this->append('echo first', Verdict::Plain, Outcome::RanOnce, OutputCheck::clean(), '10:00');
        $this->append('echo second', Verdict::Plain, Outcome::RanOnce, OutputCheck::clean(), '10:01');
        $this->append('echo third', Verdict::Plain, Outcome::RanOnce, OutputCheck::clean(), '10:02');
        $tester = $this->tester();

        $tester->execute(['--last' => '2']);

        self::assertStringNotContainsString('echo first', $tester->getDisplay());
        self::assertStringContainsString('echo second', $tester->getDisplay());
        self::assertStringContainsString('echo third', $tester->getDisplay());
    }

    public function testAnotherProjectsDecisionsAreLeftOut(): void
    {
        $this->append('ls', Verdict::Plain, Outcome::RanOnce, OutputCheck::clean(), '10:00', '/elsewhere');
        $tester = $this->tester();

        $tester->execute([]);

        self::assertStringContainsString('no decision in this project yet', $tester->getDisplay());
    }

    public function testALongCommandIsShortened(): void
    {
        $this->append('echo '.str_repeat('a', 200), Verdict::Plain, Outcome::RanOnce, OutputCheck::clean(), '10:00');
        $tester = $this->tester();

        $tester->execute([]);

        self::assertStringContainsString('echo aaa', $tester->getDisplay());
        self::assertStringNotContainsString(str_repeat('a', 100), $tester->getDisplay());
    }

    public function testACommandCannotWriteToTheTerminalItIsShownIn(): void
    {
        $this->append("echo \e[2J\e]0;owned\x07 <error>fake</error>", Verdict::Plain, Outcome::RanOnce, OutputCheck::clean(), '10:00');
        $tester = $this->tester();

        $tester->execute([], ['decorated' => true]);

        self::assertStringNotContainsString("\e[2J", $tester->getDisplay(), 'An agent writes the commands: it must not clear the screen or set the title of the terminal reading them.');
        self::assertStringNotContainsString("\x07", $tester->getDisplay());
        self::assertStringContainsString('<error>fake</error>', $tester->getDisplay(), 'A tag in a command is shown as it is, not styled.');
    }

    public function testACountThatIsNotAPositiveNumberIsRefused(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['--last' => '0']));
        self::assertStringContainsString('--last takes a number above 0', $tester->getDisplay());
    }

    private function append(string $command, Verdict $verdict, Outcome $outcome, ?OutputCheck $outputCheck, string $time, ?string $project = null): void
    {
        $log = new AuditLog(new ConfigDirectory(self::HOME, self::HOME.'/.config'), new LockFactory(new InMemoryStore()));
        $log->append(new AuditEntry(new \DateTimeImmutable('2026-11-05T'.$time.':00+00:00'), $project ?? (string) realpath(self::PROJECT), $command, $verdict, $outcome, outputCheck: $outputCheck));
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new Application(self::bootKernel())->find('log'));
    }
}
