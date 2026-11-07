<?php

declare(strict_types=1);

namespace App\Sandbox;

final readonly class NoSandbox implements Sandbox
{
    public function __construct(
        public string $reason,
    ) {
    }

    #[\Override]
    public function confine(array $command, string $projectDirectory, ?SshAgent $sshAgent = null): array
    {
        return $command;
    }

    #[\Override]
    public function cutsNetwork(): bool
    {
        return false;
    }

    #[\Override]
    public function describe(): string
    {
        return \sprintf('No sandbox (%s): the command runs with all your permissions.', $this->reason);
    }
}
