<?php

declare(strict_types=1);

namespace App\Sandbox;

use App\Network\ListenAddress;
use App\Network\ProxyEndpoint;

/**
 * What a command runs inside.
 */
interface Sandbox
{
    /**
     * The command line that runs $command inside the sandbox, from $projectDirectory. Limited to the registries, a
     * command reaches the network through $registriesProxy only; without one, as for a command reaching a remote, it
     * reaches any host.
     *
     * @param non-empty-list<string> $command
     * @param SshAgent|null          $sshAgent the agent this one command may use, or null for none
     *
     * @return non-empty-list<string>
     */
    public function confine(array $command, string $projectDirectory, ?SshAgent $sshAgent = null, ?ProxyEndpoint $registriesProxy = null): array;

    /**
     * One line saying what a command can do in it, or why there is none.
     */
    public function describe(): string;

    public function network(): NetworkAccess;

    /**
     * Whether a command in it can reach no network: without a sandbox, it can.
     */
    public function cutsNetwork(): bool;

    /**
     * Where the registries proxy listens for a command in this sandbox, given a private directory for its socket.
     *
     * @throws \LogicException when the sandbox cannot limit the network to the registries
     */
    public function proxyListenAddress(string $privateDirectory): ListenAddress;
}
