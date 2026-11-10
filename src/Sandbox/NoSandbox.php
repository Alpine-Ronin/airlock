<?php

declare(strict_types=1);

namespace App\Sandbox;

use App\Network\ListenAddress;
use App\Network\ProxyEndpoint;

final readonly class NoSandbox implements Sandbox
{
    public function __construct(
        public string $reason,
    ) {
    }

    #[\Override]
    public function confine(array $command, string $projectDirectory, ?SshAgent $sshAgent = null, ?ProxyEndpoint $registriesProxy = null): array
    {
        return $command;
    }

    #[\Override]
    public function network(): NetworkAccess
    {
        return NetworkAccess::Open;
    }

    #[\Override]
    public function cutsNetwork(): bool
    {
        return false;
    }

    #[\Override]
    public function proxyListenAddress(string $privateDirectory): ListenAddress
    {
        throw new \LogicException(\sprintf('Without a sandbox (%s), nothing keeps a command to the registries.', $this->reason));
    }

    #[\Override]
    public function describe(): string
    {
        return \sprintf('No sandbox (%s): the command runs with all your permissions.', $this->reason);
    }
}
