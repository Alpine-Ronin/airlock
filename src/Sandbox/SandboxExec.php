<?php

declare(strict_types=1);

namespace App\Sandbox;

use App\Network\ListenAddress;
use App\Network\ProxyEndpoint;

/**
 * macOS: a Seatbelt profile that allows everything but writing outside the writable paths, reading what must stay
 * unread and, when the network is cut, any network but local sockets; limited to the registries, any but the proxy's
 * port too.
 */
final readonly class SandboxExec implements Sandbox
{
    /** What macOS itself writes on behalf of any program. */
    private const array SYSTEM_WRITABLE = ['/private/var/folders', '/private/tmp', '/dev'];

    private const array AGENT_VARIABLES = ['SSH_AUTH_SOCK', 'SSH_AGENT_PID', 'GPG_AGENT_INFO'];

    public function __construct(
        private SandboxPaths $paths,
        private NetworkAccess $network,
    ) {
    }

    #[\Override]
    public function confine(array $command, string $projectDirectory, ?SshAgent $sshAgent = null, ?ProxyEndpoint $registriesProxy = null): array
    {
        $unset = [];
        foreach (self::AGENT_VARIABLES as $variable) {
            if (!$sshAgent instanceof SshAgent || 'SSH_AUTH_SOCK' !== $variable) {
                $unset[] = '-u';
                $unset[] = $variable;
            }
        }

        $proxyPort = $this->proxyPort($registriesProxy);
        $variables = [];
        if (null !== $proxyPort) {
            foreach (ProxyEndpoint::variablesFor($proxyPort) as $name => $value) {
                $variables[] = $name.'='.$value;
            }
        }

        return ['sandbox-exec', '-p', $this->profile($projectDirectory, $sshAgent instanceof SshAgent, $proxyPort), 'env', ...$unset, ...$variables, ...$command];
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
        return ListenAddress::fromOption('127.0.0.1:0');
    }

    #[\Override]
    public function describe(): string
    {
        return \sprintf(
            'Sandbox (sandbox-exec): writes only to the project, /tmp and package caches; your keys are out of reach%s.',
            NetworkDescription::of($this->network),
        );
    }

    private function profile(string $projectDirectory, bool $showsKnownHosts, ?int $proxyPort): string
    {
        $temporary = [...$this->paths->private(), ...self::SYSTEM_WRITABLE];
        $writable = array_map(
            fn (string $path): string => \sprintf('(subpath %s)', $this->quote($path)),
            [...$this->paths->writable(), ...$temporary, $projectDirectory],
        );
        // Seatbelt applies the last rule that matches: the denials come after the allowances they narrow.
        $rules = ['(version 1)', '(allow default)', '(deny file-write*)', '(allow file-write* '.implode(' ', $writable).')'];
        foreach ($this->paths->readOnlyInProject($projectDirectory, $temporary) as $readOnly) {
            $rules[] = \sprintf('(deny file-write* (subpath %s))', $this->quote($readOnly));
        }
        foreach ($this->paths->fixedInProject($projectDirectory, $temporary) as $fixed) {
            $rules[] = \sprintf('(deny file-write-unlink (literal %s))', $this->quote($fixed));
        }
        foreach ($this->paths->hidden() as $hidden) {
            // Writes too: a writable path can hold a hidden one, as when the project is the home directory.
            $rules[] = \sprintf('(deny file-read* file-write* (subpath %s))', $this->quote($hidden));
        }
        $knownHosts = $showsKnownHosts ? $this->paths->knownHosts() : null;
        if (null !== $knownHosts) {
            $rules[] = \sprintf('(allow file-read* (literal %s))', $this->quote($knownHosts));
        }
        if (NetworkAccess::None === $this->network || null !== $proxyPort) {
            $rules[] = '(deny network*)';
            $rules[] = '(allow network* (local unix))';
        }
        if (null !== $proxyPort) {
            $rules[] = \sprintf('(allow network-outbound (remote ip "localhost:%d"))', $proxyPort);
        }

        return implode("\n", $rules)."\n";
    }

    /**
     * The port the command reaches the registries through, or null when its network is not limited to them.
     */
    private function proxyPort(?ProxyEndpoint $registriesProxy): ?int
    {
        if (NetworkAccess::Registries !== $this->network || !$registriesProxy instanceof ProxyEndpoint) {
            return null;
        }
        if (null === $registriesProxy->port) {
            throw new \LogicException('sandbox-exec reaches the registries proxy through a port on 127.0.0.1, and it announced none.');
        }

        return $registriesProxy->port;
    }

    private function quote(string $path): string
    {
        return '"'.addcslashes($path, '"\\').'"';
    }
}
