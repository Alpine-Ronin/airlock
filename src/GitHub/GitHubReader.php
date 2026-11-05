<?php

declare(strict_types=1);

namespace App\GitHub;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Audit\Outcome;
use App\Execution\CommandResult;
use App\Execution\CommandRunner;
use App\Execution\ProjectDirectory;
use App\Mcp\OutputReport;
use App\Output\InjectionClassifier;
use App\Policy\Verdict;
use Mcp\Schema\Result\CallToolResult;
use Psr\Clock\ClockInterface;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Reads GitHub with the user's GitHub CLI, outside the sandbox and without asking, and gives back what it read as a
 * command's output is given back: secrets masked, checked for prompt injection, marked as data, written to the audit
 * log. Reading needs a token no command may hold, so Airlock reads for the agent instead.
 */
final readonly class GitHubReader
{
    public function __construct(
        private CommandRunner $runner,
        private InjectionClassifier $injectionClassifier,
        private OutputReport $outputReport,
        private AuditLog $auditLog,
        private ProjectDirectory $projectDirectory,
        private ClockInterface $clock,
        private ExecutableFinder $executableFinder = new ExecutableFinder(),
    ) {
    }

    /**
     * Runs each call of the GitHub CLI in turn, stopping at the first that fails, and reports their outputs as one.
     *
     * @param non-empty-list<list<string>> $calls the arguments of each call
     *
     * @throws GitHubReadException when the GitHub CLI is not installed
     */
    public function read(array $calls): CallToolResult
    {
        $this->assertCliIsInstalled();
        $results = [];
        foreach ($calls as $call) {
            $results[] = $result = $this->runner->runGitHubCli($call);
            if (!$result->isSuccessful()) {
                break;
            }
        }
        $result = $this->combined($results);
        $check = $this->injectionClassifier->check($result->output);
        $this->auditLog->append(new AuditEntry(
            $this->clock->now(),
            $this->projectDirectory->path,
            implode('; ', array_map(static fn (array $call): string => 'gh '.implode(' ', $call), $calls)),
            Verdict::Plain,
            Outcome::ReadFromGitHub,
            exitCode: $result->exitCode,
            hasTimedOut: $result->hasTimedOut,
            outputCheck: $check,
            maskedSecrets: $result->maskedSecrets,
        ));

        return $this->outputReport->result($result, $check);
    }

    /**
     * The number of the pull request the project's current branch opened.
     *
     * @throws GitHubReadException when the GitHub CLI is not installed or finds no pull request for the branch
     */
    public function currentPullRequest(): int
    {
        $this->assertCliIsInstalled();
        $result = $this->runner->runGitHubCli(['pr', 'view', '--json', 'number', '--jq', '.number']);
        $number = trim($result->output);
        if (!$result->isSuccessful() || 1 !== preg_match('/^[1-9][0-9]*$/', $number)) {
            throw new GitHubReadException(\sprintf('The GitHub CLI found no pull request for the current branch: %s', $number));
        }

        return (int) $number;
    }

    /**
     * @throws GitHubReadException
     */
    private function assertCliIsInstalled(): void
    {
        if (null === $this->executableFinder->find('gh')) {
            throw new GitHubReadException('Airlock reads GitHub with the user\'s GitHub CLI, and the GitHub CLI (gh) is not installed: ask the user to install it and log in with gh auth login.');
        }
    }

    /**
     * @param non-empty-list<CommandResult> $results
     */
    private function combined(array $results): CommandResult
    {
        $last = array_last($results);

        return new CommandResult(
            $last->exitCode,
            implode("\n", array_map(static fn (CommandResult $result): string => $result->output, $results)),
            $last->hasTimedOut,
            array_sum(array_map(static fn (CommandResult $result): int => $result->maskedSecrets, $results)),
        );
    }
}
