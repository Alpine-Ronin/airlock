<?php

declare(strict_types=1);

namespace App\Sandbox;

/**
 * macOS: a Seatbelt profile that allows everything but writing outside the writable paths, reading what must stay
 * unread and, when the network is cut, any network but local sockets.
 */
final readonly class SandboxExec implements Sandbox
{
    /** What macOS itself writes on behalf of any program. */
    private const array SYSTEM_WRITABLE = ['/private/var/folders', '/private/tmp', '/dev'];

    private const array AGENT_VARIABLES = ['SSH_AUTH_SOCK', 'SSH_AGENT_PID', 'GPG_AGENT_INFO'];

    public function __construct(
        private SandboxPaths $paths,
        private bool $cutsNetwork,
    ) {
    }

    #[\Override]
    public function confine(array $command, string $projectDirectory, ?SshAgent $sshAgent = null): array
    {
        $unset = [];
        foreach (self::AGENT_VARIABLES as $variable) {
            if (!$sshAgent instanceof SshAgent || 'SSH_AUTH_SOCK' !== $variable) {
                $unset[] = '-u';
                $unset[] = $variable;
            }
        }

        return ['sandbox-exec', '-p', $this->profile($projectDirectory, $sshAgent instanceof SshAgent), 'env', ...$unset, ...$command];
    }

    #[\Override]
    public function describe(): string
    {
        return \sprintf(
            'Sandbox (sandbox-exec): writes only to the project, /tmp and package caches; your keys are out of reach%s.',
            $this->cutsNetwork ? '; no network' : '',
        );
    }

    private function profile(string $projectDirectory, bool $showsKnownHosts): string
    {
        $writable = array_map(
            fn (string $path): string => \sprintf('(subpath %s)', $this->quote($path)),
            [...$this->paths->writable(), ...$this->paths->private(), $projectDirectory, ...self::SYSTEM_WRITABLE],
        );
        // Seatbelt applies the last rule that matches: the denials come after the allowances they narrow.
        $rules = ['(version 1)', '(allow default)', '(deny file-write*)', '(allow file-write* '.implode(' ', $writable).')'];
        foreach ($this->paths->readOnlyInProject($projectDirectory) as $readOnly) {
            $rules[] = \sprintf('(deny file-write* (subpath %s))', $this->quote($readOnly));
        }
        foreach ($this->paths->hidden() as $hidden) {
            // Writes too: a writable path can hold a hidden one, as when the project is the home directory.
            $rules[] = \sprintf('(deny file-read* file-write* (subpath %s))', $this->quote($hidden));
        }
        $knownHosts = $showsKnownHosts ? $this->paths->knownHosts() : null;
        if (null !== $knownHosts) {
            $rules[] = \sprintf('(allow file-read* (literal %s))', $this->quote($knownHosts));
        }
        if ($this->cutsNetwork) {
            $rules[] = '(deny network*)';
            $rules[] = '(allow network* (local unix))';
        }

        return implode("\n", $rules)."\n";
    }

    private function quote(string $path): string
    {
        return '"'.addcslashes($path, '"\\').'"';
    }
}
