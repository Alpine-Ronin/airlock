<?php

declare(strict_types=1);

namespace App\Tests\Sandbox;

use App\Configuration\ConfigDirectory;
use App\Sandbox\SandboxExec;
use App\Sandbox\SandboxPaths;
use App\Sandbox\SshAgent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * The macOS profile, read rather than run: sandbox-exec only exists on macOS.
 */
final class SandboxExecTest extends TestCase
{
    private const string HOME = __DIR__.'/../../var/test-sandbox-exec';

    #[\Override]
    protected function setUp(): void
    {
        new Filesystem()->mkdir(self::HOME.'/.ssh');
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove(self::HOME);
    }

    public function testTheProfileDeniesWritesButToTheProjectAndHidesTheKeys(): void
    {
        $command = new SandboxExec(new SandboxPaths(self::HOME, '/nonexistent', new ConfigDirectory(self::HOME)), cutsNetwork: false)->confine(['bash', '-c', 'ls'], '/Users/me/project');

        self::assertSame(['sandbox-exec', '-p'], \array_slice($command, 0, 2));
        self::assertSame(['env', '-u', 'SSH_AUTH_SOCK', '-u', 'SSH_AGENT_PID', '-u', 'GPG_AGENT_INFO', 'bash', '-c', 'ls'], \array_slice($command, 3));
        self::assertStringContainsString('(deny file-write*)', $command[2]);
        self::assertStringContainsString('(subpath "/Users/me/project")', $command[2]);
        self::assertStringContainsString(\sprintf('(deny file-read* file-write* (subpath "%s/.ssh"))', realpath(self::HOME)), $command[2]);
        self::assertStringNotContainsString('network', $command[2]);
    }

    public function testWithTheHomeAsTheProjectTheKeysAndAirlocksFilesStayUnwritable(): void
    {
        new Filesystem()->mkdir(self::HOME.'/.config/airlock');
        new Filesystem()->dumpFile(self::HOME.'/.ssh/known_hosts', "github.com ssh-ed25519 AAAA\n");
        $home = (string) realpath(self::HOME);
        $profile = new SandboxExec(new SandboxPaths(self::HOME, '/nonexistent', new ConfigDirectory(self::HOME)), cutsNetwork: false)->confine(['true'], $home, new SshAgent('/private/tmp/agent.sock'))[2];

        foreach ([$home.'/.ssh', $home.'/.config/airlock'] as $hidden) {
            $denied = \sprintf('(deny file-read* file-write* (subpath "%s"))', $hidden);
            self::assertStringContainsString($denied, $profile);
            self::assertGreaterThan(strpos($profile, \sprintf('(subpath "%s")', $home)), strpos($profile, $denied), 'Seatbelt applies the last rule that matches: the denial must come after the project\'s write allowance.');
        }
        self::assertStringContainsString(\sprintf('(allow file-read* (literal "%s/.ssh/known_hosts"))', $home), $profile);
        self::assertStringNotContainsString('(allow file-write* (literal', $profile, 'A command must not vouch for a forged server.');
    }

    public function testAWorktreesGitFileAndItsRepositorysHooksAndConfigurationAreDeniedWrites(): void
    {
        $repository = self::HOME.'/repository';
        new Filesystem()->mkdir($repository);
        new Process(['git', 'init', '-q'], $repository)->mustRun();
        new Process(['git', '-c', 'user.name=Airlock', '-c', 'user.email=airlock@example.invalid', '-c', 'commit.gpgsign=false', 'commit', '-q', '--allow-empty', '-m', 'Start'], $repository)->mustRun();
        new Process(['git', 'worktree', 'add', '-q', self::HOME.'/worktree'], $repository)->mustRun();
        $home = (string) realpath(self::HOME);

        $profile = new SandboxExec(new SandboxPaths(self::HOME, '/nonexistent', new ConfigDirectory(self::HOME)), cutsNetwork: false)->confine(['true'], $home.'/worktree')[2];

        foreach (['/worktree/.git', '/repository/.git/hooks', '/repository/.git/config', '/repository/.git/commondir', '/repository/.git/worktrees/worktree/hooks', '/repository/.git/worktrees/worktree/config', '/repository/.git/worktrees/worktree/commondir'] as $readOnly) {
            self::assertStringContainsString(\sprintf('(deny file-write* (subpath "%s%s"))', $home, $readOnly), $profile);
        }
    }

    public function testAGitFilePointingAtNoGitDirectoryMakesNothingThere(): void
    {
        new Filesystem()->mkdir([self::HOME.'/project', self::HOME.'/.kube']);
        new Filesystem()->dumpFile(self::HOME.'/project/.git', 'gitdir: ../.kube'."\n");
        $home = (string) realpath(self::HOME);

        $profile = new SandboxExec(new SandboxPaths(self::HOME, '/nonexistent', new ConfigDirectory(self::HOME)), cutsNetwork: false)->confine(['true'], $home.'/project')[2];

        self::assertSame(['.', '..'], scandir($home.'/.kube'), 'Nothing is made in a directory that is no git directory.');
        self::assertStringContainsString(\sprintf('(deny file-write* (subpath "%s/project/.git"))', $home), $profile);
    }

    public function testStrictCutsTheNetwork(): void
    {
        $command = new SandboxExec(new SandboxPaths(self::HOME, '/nonexistent', new ConfigDirectory(self::HOME)), cutsNetwork: true)->confine(['true'], '/p');

        self::assertStringContainsString('(deny network*)', $command[2]);
    }

    public function testALentSshAgentComesWithTheKnownHostsAlone(): void
    {
        new Filesystem()->dumpFile(self::HOME.'/.ssh/known_hosts', "github.com ssh-ed25519 AAAA\n");
        $sandbox = new SandboxExec(new SandboxPaths(self::HOME, '/nonexistent', new ConfigDirectory(self::HOME)), cutsNetwork: false);
        $allowed = \sprintf('(allow file-read* (literal "%s/.ssh/known_hosts"))', realpath(self::HOME));

        $lent = $sandbox->confine(['true'], '/p', new SshAgent('/private/tmp/agent.sock'))[2];
        $notLent = $sandbox->confine(['true'], '/p')[2];

        self::assertStringContainsString($allowed, $lent);
        self::assertGreaterThan(strpos($lent, '(deny file-read* file-write* (subpath'), strpos($lent, $allowed), 'Seatbelt applies the last rule that matches: the allowance must come after the denial of ~/.ssh.');
        self::assertStringNotContainsString('known_hosts', $notLent);
    }

    public function testALentSshAgentKeepsItsVariable(): void
    {
        $command = new SandboxExec(new SandboxPaths(self::HOME, '/nonexistent', new ConfigDirectory(self::HOME)), cutsNetwork: false)->confine(['true'], '/p', new SshAgent('/private/tmp/agent.sock'));

        self::assertSame(['env', '-u', 'SSH_AGENT_PID', '-u', 'GPG_AGENT_INFO', 'true'], \array_slice($command, 3));
    }
}
