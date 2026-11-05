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
use App\Output\OutputCheck;
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
     * @param non-empty-list<list<string>> $calls        the arguments of each call
     * @param list<int>                    $answersCodes the exit codes, besides 0, that are an answer and not a failure
     *
     * @throws GitHubReadException when the GitHub CLI is not installed
     */
    public function read(array $calls, array $answersCodes = []): CallToolResult
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
        $this->audit($calls, $result, $check);
        $report = $this->outputReport->result($result, $check);

        return \in_array($result->exitCode, $answersCodes, true) ? CallToolResult::success($report->content) : $report;
    }

    /**
     * The pull request numbered, or without a number the one the project's current branch opened.
     *
     * @throws PullRequestNotFoundException when the GitHub CLI finds no such pull request
     * @throws GitHubReadException          when the GitHub CLI is not installed
     */
    public function pullRequest(?int $number): PullRequest
    {
        $this->assertCliIsInstalled();
        $call = ['pr', 'view', ...(null === $number ? [] : [(string) $number]), '--json', 'number,url', '--jq', '.number, .url'];
        $result = $this->runner->runGitHubCli($call);
        $lines = explode("\n", trim($result->output));
        if (!$result->isSuccessful() || 2 !== \count($lines)) {
            $check = $this->injectionClassifier->check($result->output);
            $this->audit([$call], $result, $check);

            throw new PullRequestNotFoundException($this->outputReport->result($result, $check));
        }
        $this->audit([$call], $result);

        return PullRequest::fromUrl($lines[1]);
    }

    /**
     * @param non-empty-list<list<string>> $calls
     */
    private function audit(array $calls, CommandResult $result, ?OutputCheck $check = null): void
    {
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
