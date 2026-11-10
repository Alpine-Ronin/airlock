<?php

declare(strict_types=1);

namespace App\Execution;

use App\Network\InvalidNetworkFileException;
use App\Network\NetworkFiles;
use App\Network\ProxyEndpoint;
use App\Output\SecretMasker;
use App\Policy\InvalidPolicyException;
use App\Sandbox\NetworkAccess;
use App\Sandbox\SandboxDetector;
use App\Sandbox\SshAgent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs a command line with bash, in the sandbox, from the project, within a time limit, and masks the secrets it
 * printed before keeping the end of its output: a secret is never cut in two and half left in.
 */
final readonly class CommandRunner
{
    /**
     * The GitHub CLI's tokens for github.com, set when the client started Airlock with one: a command must not get a
     * token it could copy, so only Airlock's own gh calls keep them.
     */
    private const array GITHUB_COM_TOKENS = ['GH_TOKEN', 'GITHUB_TOKEN'];

    /**
     * Its tokens for GitHub Enterprise, which gh sends to whatever host the repository's remote names, and a command
     * can change the remote a linked worktree points to: no process gets them, gh's per-host logins serve instead.
     */
    private const array ENTERPRISE_TOKENS = ['GH_ENTERPRISE_TOKEN', 'GITHUB_ENTERPRISE_TOKEN'];

    /** What the agent gets back at most: the end of the output, where the result and the errors are. */
    private const int MAX_OUTPUT_BYTES = 64 * 1024;

    public function __construct(
        private SandboxDetector $sandboxDetector,
        private ProjectDirectory $projectDirectory,
        private SecretMasker $secretMasker,
        private RegistriesProxy $registriesProxy,
        private NetworkFiles $networkFiles,
        #[Autowire(env: 'int:AIRLOCK_TIMEOUT')]
        private int $timeoutSeconds,
        #[Autowire(env: 'default::SSH_AUTH_SOCK')]
        private ?string $sshAgentSocket = null,
    ) {
    }

    /**
     * @throws RegistriesProxyUnavailableException when the network is limited to the registries and no proxy starts
     * @throws InvalidNetworkFileException         when a network file cannot be read
     * @throws InvalidPolicyException              when a policy file cannot be read
     */
    public function run(string $commandLine): CommandResult
    {
        $sandbox = $this->sandboxDetector->detect();
        if (NetworkAccess::Registries !== $sandbox->network()) {
            return $this->runConfined($commandLine, null, null);
        }
        $proxy = $this->registriesProxy->start($sandbox, $this->networkFiles->settings());
        try {
            $result = $this->runConfined($commandLine, null, $proxy->endpoint);
        } finally {
            $blockedConnections = $proxy->stop();
        }

        return $result->withBlockedConnections($blockedConnections);
    }

    /**
     * Runs it with the user's SSH agent lent, when there is one: for a command the user has just approved. It reaches
     * a remote, over SSH as often as HTTPS, so its network is not limited to the registries.
     */
    public function runWithSshAgent(string $commandLine): CommandResult
    {
        $hasAgent = null !== $this->sshAgentSocket && '' !== $this->sshAgentSocket && file_exists($this->sshAgentSocket);

        return $this->runConfined($commandLine, $hasAgent ? new SshAgent($this->sshAgentSocket) : null, null);
    }

    /**
     * Runs the GitHub CLI outside the sandbox, with the user's own login: only Airlock's GitHub tools call it, with
     * arguments they build, never a command line the agent wrote.
     *
     * @param list<string> $arguments
     */
    public function runGitHubCli(array $arguments): CommandResult
    {
        return $this->runProcess(['gh', ...$arguments], [...$this->withoutAirlockEnvironment(), ...array_fill_keys(self::ENTERPRISE_TOKENS, false)]);
    }

    /**
     * Runs git outside the sandbox, as the GitHub CLI itself does: only Airlock's GitHub tools call it, with arguments
     * they build, to read what the project's branch is pushed to.
     *
     * @param list<string> $arguments
     */
    public function runGit(array $arguments): CommandResult
    {
        return $this->runProcess(['git', ...$arguments], [...$this->withoutAirlockEnvironment(), ...array_fill_keys([...self::GITHUB_COM_TOKENS, ...self::ENTERPRISE_TOKENS], false)]);
    }

    private function runConfined(string $commandLine, ?SshAgent $sshAgent, ?ProxyEndpoint $registriesProxy): CommandResult
    {
        $command = $this->sandboxDetector->detect()->confine(['bash', '-c', $commandLine], $this->projectDirectory->path, $sshAgent, $registriesProxy);

        return $this->runProcess($command, [...$this->withoutAirlockEnvironment(), ...array_fill_keys([...self::GITHUB_COM_TOKENS, ...self::ENTERPRISE_TOKENS], false)]);
    }

    /**
     * @param list<string>         $command
     * @param array<string, false> $environment the variables to unset
     */
    private function runProcess(array $command, array $environment): CommandResult
    {
        $process = new Process(
            $command,
            cwd: $this->projectDirectory->path,
            env: $environment,
            timeout: $this->timeoutSeconds,
        );

        $output = new OutputTail(2 * self::MAX_OUTPUT_BYTES);
        try {
            $process->run(static function (string $type, string $chunk) use ($output): void {
                $output->append($chunk);
            });
        } catch (ProcessTimedOutException) {
            return $this->result(null, $output, hasTimedOut: true);
        }

        return $this->result($process->getExitCode(), $output);
    }

    private function result(?int $exitCode, OutputTail $output, bool $hasTimedOut = false): CommandResult
    {
        $masked = $this->secretMasker->mask($output->text());

        return new CommandResult($exitCode, $this->tail($masked->text, $output->droppedBytes()), $hasTimedOut, $masked->maskedSecrets);
    }

    /**
     * Unsets what Airlock's own configuration added to its environment (APP_SECRET, AIRLOCK_*): the command gets the
     * environment the client started Airlock with, not Airlock's.
     *
     * @return array<string, false>
     */
    private function withoutAirlockEnvironment(): array
    {
        $inherited = getenv();
        $unset = [];
        foreach (array_keys($_ENV) as $name) {
            if (!\array_key_exists($name, $inherited)) {
                $unset[(string) $name] = false;
            }
        }
        foreach (array_keys($inherited) as $name) {
            if (str_starts_with($name, 'AIRLOCK_') || str_starts_with($name, 'SYMFONY_DOTENV_')) {
                $unset[$name] = false;
            }
        }

        return $unset;
    }

    private function tail(string $output, int $droppedBytes): string
    {
        $length = \strlen($output);
        if ($length <= self::MAX_OUTPUT_BYTES && 0 === $droppedBytes) {
            return $output;
        }
        $start = max(0, $length - self::MAX_OUTPUT_BYTES);

        return \sprintf("[the first %d bytes are cut]\n", $droppedBytes + $start).mb_strcut($output, $start);
    }
}
