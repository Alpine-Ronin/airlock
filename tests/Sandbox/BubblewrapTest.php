<?php

declare(strict_types=1);

namespace App\Tests\Sandbox;

use App\Configuration\ConfigDirectory;
use App\Execution\CommandResult;
use App\Execution\CommandRunner;
use App\Execution\ProjectDirectory;
use App\Output\SecretMasker;
use App\Sandbox\Bubblewrap;
use App\Sandbox\ReplaceableGitLinkException;
use App\Sandbox\SandboxDetector;
use App\Sandbox\SandboxMode;
use App\Sandbox\SandboxPaths;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Real commands in real bubblewrap. Everything lives under var/, which is neither the project, /tmp nor a cache:
 * a write there is a write outside what the sandbox opens.
 */
#[RequiresOperatingSystemFamily('Linux')]
final class BubblewrapTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../var/test-sandbox';

    /** In the host's /tmp, where agents put their sockets. */
    private const string HOST_TMP = '/tmp/airlock-sandbox-test';

    private Filesystem $filesystem;

    private string $root;

    private const string KNOWN_HOST = "github.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl\n";

    private ?Process $sshAgent = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->filesystem->remove([self::ROOT, self::HOST_TMP]);
        $this->filesystem->mkdir([
            self::ROOT.'/project/.git/hooks', self::ROOT.'/outside', self::ROOT.'/home/.ssh', self::ROOT.'/home/.config/airlock',
            self::ROOT.'/airlock', self::ROOT.'/run', self::HOST_TMP,
        ]);
        $this->filesystem->dumpFile(self::ROOT.'/project/.git/config', "[core]\n");
        $this->filesystem->dumpFile(self::ROOT.'/home/.ssh/id_ed25519', 'PRIVATE-KEY-VALUE');
        $this->filesystem->dumpFile(self::ROOT.'/home/.config/airlock/grants.yaml', 'GRANTS-VALUE');
        $this->filesystem->dumpFile(self::ROOT.'/airlock/.env.local', 'AIRLOCK-SECRET-VALUE');
        $this->filesystem->dumpFile(self::ROOT.'/run/keyring-control', 'RUNTIME-VALUE');
        $this->filesystem->dumpFile(self::HOST_TMP.'/host-file', 'HOST-TMP-VALUE');
        $this->root = (string) realpath(self::ROOT);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->sshAgent?->stop(0);
        $this->filesystem->remove([self::ROOT, self::HOST_TMP]);
    }

    public function testBubblewrapIsFoundAndWorksOnThisMachine(): void
    {
        $sandbox = $this->detector(SandboxMode::Auto)->detect();

        self::assertInstanceOf(Bubblewrap::class, $sandbox, $sandbox->describe());
    }

    public function testACommandWritesInTheProject(): void
    {
        $result = $this->runInSandbox('echo inside > made.txt && cat made.txt');

        self::assertSame("inside\n", $result->output);
        self::assertFileExists($this->root.'/project/made.txt');
    }

    public function testACommandWritesInAPrivateTmpThatOutlivesItNowhere(): void
    {
        $result = $this->runInSandbox('echo t > /tmp/airlock-made && cat /tmp/airlock-made');

        self::assertSame("t\n", $result->output);
        self::assertFileDoesNotExist('/tmp/airlock-made');
    }

    public function testACommandDoesNotSeeTheHostsTmp(): void
    {
        $result = $this->runInSandbox(\sprintf('cat %s/host-file; echo end', self::HOST_TMP));

        self::assertStringNotContainsString('HOST-TMP-VALUE', $result->output);
    }

    public function testACommandDoesNotSeeTheRuntimeDirectory(): void
    {
        $result = $this->runInSandbox(\sprintf('cat %s/run/keyring-control; echo end', $this->root));

        self::assertStringNotContainsString('RUNTIME-VALUE', $result->output);
    }

    public function testAProjectInsideTmpStaysWritable(): void
    {
        $project = self::HOST_TMP.'/project';
        $this->filesystem->mkdir($project);

        $result = $this->runInSandbox('echo inside > made.txt && cat made.txt', project: $project);

        self::assertSame("inside\n", $result->output);
        self::assertFileExists($project.'/made.txt');
    }

    public function testAWriteOutsideTheProjectFails(): void
    {
        $result = $this->runInSandbox(\sprintf('echo escaped > %s/outside/escaped.txt', $this->root));

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('Read-only file system', $result->output);
        self::assertFileDoesNotExist($this->root.'/outside/escaped.txt');
    }

    public function testAWriteToTheHomeFails(): void
    {
        $result = $this->runInSandbox(\sprintf('echo pwned >> %s/home/.bashrc', $this->root));

        self::assertFalse($result->isSuccessful());
        self::assertFileDoesNotExist($this->root.'/home/.bashrc');
    }

    public function testADirectoryBesideTheProjectCannotBeMade(): void
    {
        $this->runInSandbox(\sprintf('mkdir %s/escape', $this->root));

        self::assertDirectoryDoesNotExist($this->root.'/escape');
    }

    public function testAGitHookCannotBePlanted(): void
    {
        $result = $this->runInSandbox('echo "rm -rf ~" > .git/hooks/pre-commit');

        self::assertFalse($result->isSuccessful());
        self::assertFileDoesNotExist($this->root.'/project/.git/hooks/pre-commit');
    }

    public function testAGitHookCannotBePlantedWhenTheHooksDirectoryIsMissing(): void
    {
        $this->filesystem->remove($this->root.'/project/.git/hooks');

        $this->runInSandbox('mkdir -p .git/hooks && echo "rm -rf ~" > .git/hooks/pre-commit');

        self::assertFileDoesNotExist($this->root.'/project/.git/hooks/pre-commit');
    }

    public function testAGitConfigurationCannotBePlantedWhenItIsMissing(): void
    {
        $this->filesystem->remove($this->root.'/project/.git/config');

        $this->runInSandbox('printf "[core]\n\thooksPath = /tmp/evil\n" > .git/config');

        self::assertSame('', $this->filesystem->readFile($this->root.'/project/.git/config'));
    }

    public function testAGitCommonDirectoryCannotBePlanted(): void
    {
        $this->runInSandbox('git init -q planted && echo "../planted/.git" > .git/commondir');

        self::assertSame(".\n", $this->filesystem->readFile($this->root.'/project/.git/commondir'), 'Git would take its hooks and configuration from the planted repository.');
    }

    public function testAWorktreeConfigurationCannotBePlanted(): void
    {
        $this->runInSandbox('printf "[core]\n\thooksPath = /tmp/hooks\n" > .git/config.worktree');

        self::assertSame('', $this->filesystem->readFile($this->root.'/project/.git/config.worktree'), 'Git reads it once extensions.worktreeConfig is set.');
    }

    public function testTheCommonDirectoryARepositoryNamesKeepsItsHooksWhereACommandMayWrite(): void
    {
        $common = $this->root.'/home/.cache/common';
        $this->filesystem->mkdir($common);
        new Process(['git', 'init', '-q', '--bare'], $common)->mustRun();
        $this->filesystem->dumpFile($this->root.'/project/.git/commondir', $common."\n");

        $this->runInSandbox(\sprintf('echo "rm -rf ~" > %s/hooks/pre-commit', escapeshellarg($common)));

        self::assertFileDoesNotExist($common.'/hooks/pre-commit');
    }

    public function testACommonDirectoryWithoutHeadKeepsItsHooksAndConfigurationWhereACommandMayWrite(): void
    {
        $common = $this->root.'/home/.cache/common';
        $this->filesystem->mkdir($common);
        new Process(['git', 'init', '-q', '--bare'], $common)->mustRun();
        $this->filesystem->remove($common.'/HEAD');
        $configuration = $this->filesystem->readFile($common.'/config');
        $this->filesystem->dumpFile($this->root.'/project/.git/commondir', $common."\n");

        $this->runInSandbox(\sprintf('cd %s && echo "rm -rf ~" > hooks/pre-commit; echo "[core] hooksPath = /tmp/hooks" >> config', escapeshellarg($common)));

        self::assertFileDoesNotExist($common.'/hooks/pre-commit', 'Git still takes its hooks from a common directory without HEAD.');
        self::assertSame($configuration, $this->filesystem->readFile($common.'/config'));
    }

    public function testTheGitConfigurationCannotBeChanged(): void
    {
        $this->runInSandbox('echo "[core] hooksPath = /tmp/hooks" >> .git/config');

        self::assertSame("[core]\n", $this->filesystem->readFile($this->root.'/project/.git/config'));
    }

    public function testTheRestOfTheGitDirectoryStaysWritable(): void
    {
        $this->runInSandbox('touch .git/index');

        self::assertFileExists($this->root.'/project/.git/index');
    }

    public function testAGitDirectoryCannotBeMovedAsideForOneOfItsOwn(): void
    {
        $result = $this->runInSandbox('mv .git .old && git init -q && echo "rm -rf ~" > .git/hooks/pre-commit');

        self::assertFalse($result->isSuccessful());
        self::assertDirectoryDoesNotExist($this->root.'/project/.old');
        self::assertFileDoesNotExist($this->root.'/project/.git/hooks/pre-commit');
    }

    public function testASymbolicLinkToTheHooksStopsTheCommand(): void
    {
        $this->filesystem->rename($this->root.'/project/.git/hooks', $this->root.'/project/hooks');
        $this->filesystem->symlink('../hooks', $this->root.'/project/.git/hooks');

        $this->expectException(ReplaceableGitLinkException::class);
        $this->expectExceptionMessageIsOrContains($this->root.'/project/.git/hooks is a symbolic link');

        $this->runInSandbox('true');
    }

    public function testASymbolicLinkToTheGitDirectoryStopsTheCommand(): void
    {
        $this->filesystem->rename($this->root.'/project/.git', $this->root.'/outside/.git');
        $this->filesystem->symlink($this->root.'/outside/.git', $this->root.'/project/.git');

        $this->expectException(ReplaceableGitLinkException::class);

        $this->runInSandbox('true');
    }

    public function testAWorktreesGitFileCannotBeRewritten(): void
    {
        $worktree = $this->worktreeOf($this->root.'/repository');
        $pointer = $this->filesystem->readFile($worktree.'/.git');

        $result = $this->runInSandbox('printf "gitdir: elsewhere\n" > .git', project: $worktree);

        self::assertFalse($result->isSuccessful());
        self::assertSame($pointer, $this->filesystem->readFile($worktree.'/.git'));
    }

    public function testAGitHookCannotBePlantedThroughAWorktree(): void
    {
        $worktree = $this->worktreeOf($this->root.'/repository');

        $this->runInSandbox('git init -q planted && echo "rm -rf ~" > planted/.git/hooks/pre-commit && printf "gitdir: planted/.git\n" > .git', project: $worktree);

        self::assertFileDoesNotExist($this->gitPath($worktree, 'hooks/pre-commit'));
    }

    public function testTheGitConfigurationCannotBeChangedThroughAWorktree(): void
    {
        $worktree = $this->worktreeOf($this->root.'/repository');

        $this->runInSandbox('git init -q planted && git -C planted config core.hooksPath /tmp/hooks && printf "gitdir: planted/.git\n" > .git', project: $worktree);

        self::assertSame('', $this->gitConfig($worktree, 'core.hooksPath'));
    }

    public function testAWorktreesRepositoryKeepsItsHooksWhereACommandMayWrite(): void
    {
        $worktree = $this->worktreeOf($this->root.'/home/.cache/repository');
        $hook = $this->gitPath($worktree, 'hooks/pre-commit');

        $this->runInSandbox(\sprintf('echo "rm -rf ~" > %s', escapeshellarg($hook)), project: $worktree);

        self::assertFileDoesNotExist($hook);
    }

    public function testAWorktreesGitDirectoryKeepsItsCommonDirectoryWhereACommandMayWrite(): void
    {
        $worktree = $this->worktreeOf($this->root.'/home/.cache/repository');
        $commonDirectoryFile = $this->root.'/home/.cache/repository/.git/worktrees/worktree/commondir';
        $commonDirectory = $this->filesystem->readFile($commonDirectoryFile);

        $this->runInSandbox(\sprintf('echo "../../../../../worktree/planted/.git" > %s', escapeshellarg($commonDirectoryFile)), project: $worktree);

        self::assertSame($commonDirectory, $this->filesystem->readFile($commonDirectoryFile));
    }

    public function testAWorktreesRepositoryKeepsItsHooksWhereACommandMayWriteWhenItsGitDirectoryHasNoHead(): void
    {
        $worktree = $this->worktreeOf($this->root.'/home/.cache/repository');
        $this->filesystem->remove($this->root.'/home/.cache/repository/.git/worktrees/worktree/HEAD');
        $hook = $this->root.'/home/.cache/repository/.git/hooks/pre-commit';

        $this->runInSandbox(\sprintf('echo "rm -rf ~" > %s', escapeshellarg($hook)), project: $worktree);

        self::assertFileDoesNotExist($hook, 'A command that stripped HEAD in an earlier run could restore it after planting the hook.');
    }

    public function testAWorktreesRepositoryCannotBeMovedAsideWhereACommandMayWrite(): void
    {
        $worktree = $this->worktreeOf($this->root.'/home/.cache/repository');

        $this->runInSandbox(\sprintf('mv %1$s/repository %1$s/moved', escapeshellarg($this->root.'/home/.cache')), project: $worktree);

        self::assertDirectoryExists($this->root.'/home/.cache/repository/.git/worktrees/worktree');
        self::assertDirectoryDoesNotExist($this->root.'/home/.cache/moved');
    }

    public function testAWorktreeWhoseRepositoryIsWhereACommandMayWriteStillCommits(): void
    {
        $worktree = $this->worktreeOf($this->root.'/home/.cache/repository');

        $result = $this->runInSandbox('git -c user.name=Airlock -c user.email=airlock@example.invalid -c commit.gpgsign=false commit -q --allow-empty -m Inside && git log --format=%s -1', project: $worktree);

        self::assertTrue($result->isSuccessful(), $result->output);
        self::assertStringContainsString('Inside', $result->output);
    }

    public function testAWorktreeReachingItsRepositoryThroughASymbolicLinkACommandMayReplaceStopsTheCommand(): void
    {
        $worktree = $this->worktreeOf($this->root.'/repository');
        $this->filesystem->symlink($this->root.'/repository', $this->root.'/home/.cache/link');
        $this->filesystem->dumpFile($worktree.'/.git', \sprintf("gitdir: %s/home/.cache/link/.git/worktrees/worktree\n", $this->root));

        $this->expectException(ReplaceableGitLinkException::class);
        $this->expectExceptionMessageIsOrContains($this->root.'/home/.cache/link is a symbolic link');

        $this->runInSandbox('true', project: $worktree);
    }

    public function testASymbolicLinkACommandCannotReplaceDoesNotStopIt(): void
    {
        $worktree = $this->worktreeOf($this->root.'/repository');
        $this->filesystem->rename($this->root.'/repository/.git/hooks', $this->root.'/repository/hooks');
        $this->filesystem->symlink('../hooks', $this->root.'/repository/.git/hooks');

        $result = $this->runInSandbox('echo ran', project: $worktree);

        self::assertSame("ran\n", $result->output, 'Outside the writable directories, a command cannot replace the link.');
    }

    public function testAWorktreesRepositoryKeepsItsConfigurationWhereACommandMayWrite(): void
    {
        $worktree = $this->worktreeOf($this->root.'/home/.cache/repository');

        $this->runInSandbox('git config core.hooksPath /tmp/hooks', project: $worktree);

        self::assertSame('', $this->gitConfig($worktree, 'core.hooksPath'));
    }

    public function testTheUsersKeysCannotBeRead(): void
    {
        $result = $this->runInSandbox(\sprintf('cat %s/home/.ssh/id_ed25519; echo end', $this->root));

        self::assertStringNotContainsString('PRIVATE-KEY-VALUE', $result->output);
        self::assertStringContainsString('end', $result->output);
    }

    public function testTheUsersRegistryTokensCannotBeRead(): void
    {
        $this->filesystem->dumpFile($this->root.'/home/.npmrc', 'NPM-TOKEN-VALUE');
        $this->filesystem->dumpFile($this->root.'/home/.config/composer/auth.json', 'COMPOSER-TOKEN-VALUE');

        $result = $this->runInSandbox(\sprintf('cat %1$s/home/.npmrc %1$s/home/.config/composer/auth.json; echo end', $this->root));

        self::assertStringNotContainsString('NPM-TOKEN-VALUE', $result->output);
        self::assertStringNotContainsString('COMPOSER-TOKEN-VALUE', $result->output);
        self::assertStringContainsString('end', $result->output);
    }

    public function testAirlocksOwnFilesCannotBeRead(): void
    {
        $result = $this->runInSandbox(\sprintf('cat %1$s/home/.config/airlock/grants.yaml %1$s/airlock/.env.local; echo end', $this->root));

        self::assertStringNotContainsString('GRANTS-VALUE', $result->output);
        self::assertStringNotContainsString('AIRLOCK-SECRET-VALUE', $result->output);
    }

    public function testAirlocksOwnFilesCannotBeChanged(): void
    {
        $this->runInSandbox(\sprintf('echo "rm -rf *" > %s/home/.config/airlock/grants.yaml', $this->root));

        self::assertSame('GRANTS-VALUE', $this->filesystem->readFile($this->root.'/home/.config/airlock/grants.yaml'));
    }

    public function testTheSshAgentIsOutOfReachByDefault(): void
    {
        $socket = $this->startSshAgent();

        $result = $this->runInSandbox(\sprintf('echo "[$SSH_AUTH_SOCK]"; SSH_AUTH_SOCK=%s ssh-add -l; echo "exit $?"', $socket), sshAgentSocket: $socket);

        self::assertStringStartsWith("[]\n", $result->output, 'The variable naming the agent is unset.');
        self::assertStringEndsWith("exit 2\n", $result->output, 'ssh-add exits with 2 when it cannot reach an agent.');
    }

    public function testTheSshAgentCanBeLentToOneCommand(): void
    {
        $socket = $this->startSshAgent();

        $result = $this->runner(SandboxMode::Auto, 30, null, $socket)->runWithSshAgent('ssh-add -l; echo "exit $?"');

        self::assertStringEndsWith("exit 1\n", $result->output, 'ssh-add exits with 1 when it reaches an agent that holds no key.');
    }

    public function testACommandLentTheSshAgentReadsTheKnownHostsAndNoKey(): void
    {
        $this->filesystem->dumpFile($this->root.'/home/.ssh/known_hosts', self::KNOWN_HOST);
        $socket = $this->startSshAgent();
        $knownHosts = $this->root.'/home/.ssh/known_hosts';

        $result = $this->runner(SandboxMode::Auto, 30, null, $socket)->runWithSshAgent(\sprintf('ssh-keygen -F github.com -f %1$s; echo "exit $?"; cat %2$s/home/.ssh/id_ed25519; echo forged >> %1$s; echo end', $knownHosts, $this->root));

        self::assertStringContainsString('# Host github.com found: line 1', $result->output, 'Without the known hosts, ssh refuses to connect: Host key verification failed.');
        self::assertStringNotContainsString('PRIVATE-KEY-VALUE', $result->output, 'The keys stay hidden.');
        self::assertSame(self::KNOWN_HOST, $this->filesystem->readFile($knownHosts), 'A command must not vouch for a forged server.');
    }

    public function testTheKnownHostsAreHiddenFromACommandNotLentTheSshAgent(): void
    {
        $this->filesystem->dumpFile($this->root.'/home/.ssh/known_hosts', self::KNOWN_HOST);

        $result = $this->runInSandbox(\sprintf('cat %s/home/.ssh/known_hosts; echo end', $this->root));

        self::assertStringNotContainsString('github.com', $result->output, 'Which servers the user reaches is theirs to tell.');
        self::assertStringContainsString('end', $result->output);
    }

    public function testStrictModeLeavesNoNetworkButLoopback(): void
    {
        $result = $this->runInSandbox("tail -n +3 /proc/net/dev | cut -d: -f1 | tr -d ' '", SandboxMode::Strict);

        self::assertSame("lo\n", $result->output);
    }

    public function testAutoModeKeepsTheNetwork(): void
    {
        $result = $this->runInSandbox("tail -n +3 /proc/net/dev | cut -d: -f1 | tr -d ' '");

        self::assertNotSame("lo\n", $result->output);
    }

    public function testACommandDoesNotSeeAirlocksOwnEnvironment(): void
    {
        $secret = $_ENV['APP_SECRET'] ?? null;
        self::assertIsString($secret, 'The test environment sets APP_SECRET in .env.test.');

        $result = $this->runInSandbox('env');

        self::assertStringNotContainsString($secret, $result->output, 'APP_SECRET comes from Airlock\'s .env files.');
        self::assertStringNotContainsString('SYMFONY_DOTENV_VARS', $result->output);
    }

    public function testACommandPastItsTimeLimitIsStopped(): void
    {
        $result = $this->runInSandbox('echo started; sleep 10', timeoutSeconds: 1);

        self::assertTrue($result->hasTimedOut);
        self::assertNull($result->exitCode);
        self::assertSame("started\n", $result->output);
    }

    public function testTheExitCodeIsKept(): void
    {
        self::assertSame(3, $this->runInSandbox('exit 3')->exitCode);
    }

    public function testAnOutputFarPastTheLimitKeepsItsEndAndSaysHowMuchIsCut(): void
    {
        $result = $this->runInSandbox("head -c 1000000 /dev/zero | tr '\\0' x; echo; echo the end");

        self::assertStringStartsWith(\sprintf("[the first %d bytes are cut]\n", 1000009 - 65536), $result->output);
        self::assertStringEndsWith("x\nthe end\n", $result->output);
        self::assertSame(65536, \strlen($result->output) - \strlen(\sprintf("[the first %d bytes are cut]\n", 1000009 - 65536)));
    }

    public function testASecretTheCommandPrintsIsMasked(): void
    {
        $result = $this->runInSandbox('echo DB_PASSWORD=hunter2');

        self::assertSame("DB_PASSWORD=[masked: secret-assignment]\n", $result->output);
        self::assertSame(1, $result->maskedSecrets);
    }

    public function testASecretWhereTheOutputIsCutIsMaskedWhole(): void
    {
        $result = $this->runInSandbox("printf 'AKIAIOSFODNN7EXAMPLE\\n'; head -c 65525 /dev/zero | tr '\\0' x");

        self::assertStringStartsWith('[the first 14 bytes are cut]', $result->output);
        self::assertStringNotContainsString('EXAMPLE', $result->output);
        self::assertSame(1, $result->maskedSecrets);
    }

    private function startSshAgent(): string
    {
        $socket = self::HOST_TMP.'/agent.sock';
        $this->sshAgent = new Process(['ssh-agent', '-D', '-a', $socket]);
        $this->sshAgent->start();
        for ($attempt = 0; $attempt < 100 && !file_exists($socket); ++$attempt) {
            usleep(20_000);
        }
        self::assertFileExists($socket, 'ssh-agent did not start: '.$this->sshAgent->getErrorOutput());

        return $socket;
    }

    /**
     * A linked worktree of a new repository made at $repository, as `git worktree add` makes it: its `.git` is a file
     * pointing into the repository's own git directory.
     */
    private function worktreeOf(string $repository): string
    {
        $worktree = $this->root.'/worktree';
        $this->filesystem->mkdir($repository);
        new Process(['git', 'init', '-q'], $repository)->mustRun();
        new Process(['git', '-c', 'user.name=Airlock', '-c', 'user.email=airlock@example.invalid', '-c', 'commit.gpgsign=false', 'commit', '-q', '--allow-empty', '-m', 'Start'], $repository)->mustRun();
        new Process(['git', 'worktree', 'add', '-q', $worktree], $repository)->mustRun();

        return $worktree;
    }

    /** Where git, run later outside the sandbox, finds $path of the worktree's repository. */
    private function gitPath(string $worktree, string $path): string
    {
        return trim(new Process(['git', 'rev-parse', '--path-format=absolute', '--git-path', $path], $worktree)->mustRun()->getOutput());
    }

    private function gitConfig(string $worktree, string $key): string
    {
        $process = new Process(['git', 'config', '--get', $key], $worktree);
        $process->run();

        return trim($process->getOutput());
    }

    public function testLimitedToTheRegistriesAHostOffTheListIsBlockedAndNamed(): void
    {
        $result = $this->runInSandbox('curl -sS --max-time 10 https://evil.example/; echo "exit $?"', SandboxMode::Registries);

        self::assertStringContainsString('CONNECT tunnel failed, response 403', $result->output);
        self::assertStringContainsString('exit 56', $result->output);
        self::assertSame(['evil.example:443'], $result->blockedConnections);
    }

    public function testLimitedToTheRegistriesNothingIsReachableAroundTheProxy(): void
    {
        $result = $this->runInSandbox("curl -sS --noproxy '*' --max-time 5 http://151.101.0.1/; echo \"exit $?\"", SandboxMode::Registries);

        self::assertMatchesRegularExpression('/exit (6|7|28)\b/', $result->output, 'Without the proxy, the command has no route out: '.$result->output);
        self::assertSame([], $result->blockedConnections);
    }

    public function testLimitedToTheRegistriesTheCommandsExitCodeAndOutputComeBack(): void
    {
        $result = $this->runInSandbox('echo "proxy: $HTTPS_PROXY"; echo out; echo err >&2; exit 3', SandboxMode::Registries);

        self::assertSame(3, $result->exitCode);
        self::assertStringContainsString("proxy: http://127.0.0.1:3128\nout\nerr\n", $result->output);
    }

    public function testLimitedToTheRegistriesACommandPastItsTimeLimitStopsWithItsProcesses(): void
    {
        $result = $this->runInSandbox('(sleep 2; touch late) & echo started; sleep 10', SandboxMode::Registries, timeoutSeconds: 1);
        sleep(3);

        self::assertTrue($result->hasTimedOut);
        self::assertSame("started\n", $result->output);
        self::assertFileDoesNotExist($this->root.'/project/late', 'The command kept running after Airlock said it was stopped.');
    }

    public function testLimitedToTheRegistriesACommandsOwnLocalServerStaysReachable(): void
    {
        $result = $this->runInSandbox('php -S 127.0.0.1:8099 -t . >/dev/null 2>&1 & sleep 0.5; curl -sS -o /dev/null -w "%{http_code}" http://127.0.0.1:8099/missing; kill %1', SandboxMode::Registries);

        self::assertSame('404', trim($result->output));
    }

    private function runInSandbox(string $commandLine, SandboxMode $mode = SandboxMode::Auto, int $timeoutSeconds = 30, ?string $project = null, ?string $sshAgentSocket = null): CommandResult
    {
        return $this->runner($mode, $timeoutSeconds, $project, $sshAgentSocket)->run($commandLine);
    }

    private function runner(SandboxMode $mode, int $timeoutSeconds, ?string $project, ?string $sshAgentSocket): CommandRunner
    {
        return new CommandRunner($this->detector($mode), new ProjectDirectory($project ?? $this->root.'/project'), new SecretMasker(), $timeoutSeconds, $sshAgentSocket);
    }

    private function detector(SandboxMode $mode): SandboxDetector
    {
        $home = $this->root.'/home';
        $paths = new SandboxPaths($home, $this->root.'/airlock', new ConfigDirectory($home), $this->root.'/run');

        return new SandboxDetector($paths, new ProjectDirectory($this->root.'/project'), $mode);
    }
}
