<?php

declare(strict_types=1);

namespace App\Sandbox;

/**
 * What a command runs inside.
 */
interface Sandbox
{
    /**
     * The command line that runs $command inside the sandbox, from $projectDirectory.
     *
     * @param non-empty-list<string> $command
     * @param SshAgent|null          $sshAgent the agent this one command may use, or null for none
     *
     * @return non-empty-list<string>
     */
    public function confine(array $command, string $projectDirectory, ?SshAgent $sshAgent = null): array;

    /**
     * One line saying what a command can do in it, or why there is none.
     */
    public function describe(): string;

    /**
     * Whether a command in it can reach no network: without a sandbox, it can.
     */
    public function cutsNetwork(): bool;
}
