<?php

declare(strict_types=1);

namespace App\Sandbox;

/**
 * Linux: the whole system mounted read-only, the writable paths bound back writable, private temporary directories,
 * and what must stay unread covered by an empty, read-only mount.
 */
final readonly class Bubblewrap implements Sandbox
{
    /** Where a lent SSH agent appears inside: in the private /tmp, so it exists for this command only. */
    private const string SSH_AGENT_SOCKET = '/tmp/airlock-ssh-agent.sock';

    /** Variables that point a command at an agent acting with the user's credentials. */
    private const array AGENT_VARIABLES = ['SSH_AUTH_SOCK', 'SSH_AGENT_PID', 'GPG_AGENT_INFO', 'DBUS_SESSION_BUS_ADDRESS'];

    public function __construct(
        private SandboxPaths $paths,
        private bool $cutsNetwork,
        private string $executable = 'bwrap',
    ) {
    }

    #[\Override]
    public function confine(array $command, string $projectDirectory, ?SshAgent $sshAgent = null): array
    {
        $arguments = [$this->executable, '--ro-bind', '/', '/', '--dev', '/dev', '--proc', '/proc'];
        foreach ($this->paths->private() as $private) {
            $arguments[] = '--tmpfs';
            $arguments[] = $private;
        }
        foreach ([...$this->paths->writable(), $projectDirectory] as $writable) {
            $arguments[] = '--bind';
            $arguments[] = $writable;
            $arguments[] = $writable;
        }
        // A mount point can be written in but not moved or removed. The temporary directories are private: nothing
        // there outlives the command.
        foreach ($this->paths->fixedInProject($projectDirectory, alsoWritable: []) as $fixed) {
            $arguments[] = '--bind';
            $arguments[] = $fixed;
            $arguments[] = $fixed;
        }
        foreach ($this->paths->readOnlyInProject($projectDirectory, alsoWritable: []) as $readOnly) {
            $arguments[] = '--ro-bind';
            $arguments[] = $readOnly;
            $arguments[] = $readOnly;
        }
        foreach ($this->paths->hidden() as $hidden) {
            array_push($arguments, ...$this->hide($hidden));
        }
        $knownHosts = $sshAgent instanceof SshAgent ? $this->paths->knownHosts() : null;
        if (null !== $knownHosts) {
            array_push($arguments, ...$this->showReadOnly($knownHosts));
        }
        foreach (self::AGENT_VARIABLES as $variable) {
            $arguments[] = '--unsetenv';
            $arguments[] = $variable;
        }
        if ($sshAgent instanceof SshAgent) {
            $arguments[] = '--bind';
            $arguments[] = $sshAgent->socket;
            $arguments[] = self::SSH_AGENT_SOCKET;
            $arguments[] = '--setenv';
            $arguments[] = 'SSH_AUTH_SOCK';
            $arguments[] = self::SSH_AGENT_SOCKET;
        }
        if ($this->cutsNetwork) {
            $arguments[] = '--unshare-net';
        }
        // --new-session: a command must not reach the terminal Airlock runs in (TIOCSTI).
        array_push($arguments, '--die-with-parent', '--new-session', '--chdir', $projectDirectory, '--', ...$command);

        return $arguments;
    }

    #[\Override]
    public function cutsNetwork(): bool
    {
        return $this->cutsNetwork;
    }

    #[\Override]
    public function describe(): string
    {
        return \sprintf(
            'Sandbox (bubblewrap): writes only to the project and package caches, with a private /tmp; your keys and agents are out of reach%s.',
            $this->cutsNetwork ? '; no network' : '',
        );
    }

    /**
     * A file inside a hidden directory, bound back read-only: the empty directory covering it is made writable just
     * long enough to make the mount point, then read-only again.
     *
     * @return list<string>
     */
    private function showReadOnly(string $file): array
    {
        $directory = \dirname($file);

        return ['--tmpfs', $directory, '--ro-bind', $file, $file, '--remount-ro', $directory];
    }

    /**
     * @return list<string>
     */
    private function hide(string $path): array
    {
        if (is_dir($path)) {
            return ['--tmpfs', $path, '--remount-ro', $path];
        }

        return ['--ro-bind', '/dev/null', $path];
    }
}
