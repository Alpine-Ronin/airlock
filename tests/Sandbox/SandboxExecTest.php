<?php

declare(strict_types=1);

namespace App\Tests\Sandbox;

use App\Configuration\ConfigDirectory;
use App\Sandbox\SandboxExec;
use App\Sandbox\SandboxPaths;
use App\Sandbox\SshAgent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

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
        self::assertStringContainsString(\sprintf('(deny file-read* (subpath "%s/.ssh"))', realpath(self::HOME)), $command[2]);
        self::assertStringNotContainsString('network', $command[2]);
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
        self::assertGreaterThan(strpos($lent, '(deny file-read* (subpath'), strpos($lent, $allowed), 'Seatbelt applies the last rule that matches: the allowance must come after the denial of ~/.ssh.');
        self::assertStringNotContainsString('known_hosts', $notLent);
    }

    public function testALentSshAgentKeepsItsVariable(): void
    {
        $command = new SandboxExec(new SandboxPaths(self::HOME, '/nonexistent', new ConfigDirectory(self::HOME)), cutsNetwork: false)->confine(['true'], '/p', new SshAgent('/private/tmp/agent.sock'));

        self::assertSame(['env', '-u', 'SSH_AGENT_PID', '-u', 'GPG_AGENT_INFO', 'true'], \array_slice($command, 3));
    }
}
