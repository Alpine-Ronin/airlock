<?php

declare(strict_types=1);

namespace App\Sandbox;

use App\Network\ListenAddress;
use App\Network\ProxyEndpoint;

/**
 * Linux: the whole system mounted read-only, the writable paths bound back writable, private temporary directories,
 * and what must stay unread covered by an empty, read-only mount.
 */
final readonly class Bubblewrap implements Sandbox
{
    /** Where a lent SSH agent appears inside: in the private /tmp, so it exists for this command only. */
    private const string SSH_AGENT_SOCKET = '/tmp/airlock-ssh-agent.sock';

    /** Where the registries proxy's socket appears inside, in the private /tmp, for the relay to reach. */
    private const string PROXY_SOCKET = '/tmp/airlock-proxy.sock';

    /**
     * The port the relay listens on, in the command's own network namespace: nothing else listens there when it starts.
     */
    private const int RELAY_PORT = 3128;

    /** Variables that point a command at an agent acting with the user's credentials. */
    private const array AGENT_VARIABLES = ['SSH_AUTH_SOCK', 'SSH_AGENT_PID', 'GPG_AGENT_INFO', 'DBUS_SESSION_BUS_ADDRESS'];

    /**
     * @param non-empty-list<string> $airlockCommand how Airlock itself is started, to run the relay inside
     */
    public function __construct(
        private SandboxPaths $paths,
        private NetworkAccess $network,
        private array $airlockCommand,
        private string $executable = 'bwrap',
    ) {
    }

    #[\Override]
    public function confine(array $command, string $projectDirectory, ?SshAgent $sshAgent = null, ?ProxyEndpoint $registriesProxy = null): array
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
        $isThroughProxy = NetworkAccess::Registries === $this->network && $registriesProxy instanceof ProxyEndpoint;
        if (NetworkAccess::None === $this->network || $isThroughProxy) {
            $arguments[] = '--unshare-net';
        }
        if ($isThroughProxy) {
            // The relay stands between bubblewrap and the command: its own PID namespace dies with bubblewrap, so a
            // command stopped past its time limit takes its processes with it.
            $arguments[] = '--unshare-pid';
            array_push($arguments, ...$this->proxyRoute($registriesProxy));
            $command = [...$this->airlockCommand, 'network-relay', self::PROXY_SOCKET, (string) self::RELAY_PORT, '--', ...$command];
        }
        // --new-session: a command must not reach the terminal Airlock runs in (TIOCSTI).
        array_push($arguments, '--die-with-parent', '--new-session', '--chdir', $projectDirectory, '--', ...$command);

        return $arguments;
    }

    #[\Override]
    public function network(): NetworkAccess
    {
        return $this->network;
    }

    #[\Override]
    public function cutsNetwork(): bool
    {
        return NetworkAccess::None === $this->network;
    }

    #[\Override]
    public function proxyListenAddress(string $privateDirectory): ListenAddress
    {
        return ListenAddress::fromOption('unix://'.$privateDirectory.'/proxy.sock');
    }

    #[\Override]
    public function describe(): string
    {
        return \sprintf(
            'Sandbox (bubblewrap): writes only to the project and package caches, with a private /tmp; your keys and agents are out of reach%s.',
            NetworkDescription::of($this->network),
        );
    }

    /**
     * The proxy's socket bound into the private /tmp, and the variables that send the tools to the relay in front of it.
     *
     * @return list<string>
     */
    private function proxyRoute(ProxyEndpoint $registriesProxy): array
    {
        if (null === $registriesProxy->socketFile) {
            throw new \LogicException('bubblewrap reaches the registries proxy through its socket file, and it announced none.');
        }
        $arguments = ['--bind', $registriesProxy->socketFile, self::PROXY_SOCKET];
        foreach (ProxyEndpoint::variablesFor(self::RELAY_PORT) as $name => $value) {
            $arguments[] = '--setenv';
            $arguments[] = $name;
            $arguments[] = $value;
        }

        return $arguments;
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
