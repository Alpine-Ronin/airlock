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

/**
 * Opens a pull request from the project's current branch with the user's GitHub CLI, outside the sandbox, once the
 * user has allowed it: Airlock builds the call itself, never pushes, and gives back what gh printed as a command's
 * output is given back.
 */
final readonly class PullRequestOpener
{
    /** Why opening a pull request is asked every time, in the audit log. */
    public const string REASON = 'it opens a pull request on GitHub, as you';

    /** Where a branch pushed without an upstream is looked for: a confined command cannot record an upstream. */
    private const string DEFAULT_REMOTE = 'origin';

    /** Each branch and remote branch: whether it is checked out, its name, and its upstream's remote and branch. */
    private const string BRANCHES_FORMAT = '%(HEAD)%09%(refname)%09%(upstream:remotename)%09%(upstream:remoteref)';

    private const string LOCAL_BRANCHES = 'refs/heads/';

    public function __construct(
        private GitHubReader $reader,
        private CommandRunner $runner,
        private InjectionClassifier $injectionClassifier,
        private OutputReport $outputReport,
        private AuditLog $auditLog,
        private ProjectDirectory $projectDirectory,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The pull request that would be opened from the current branch, with what the agent wrote.
     *
     * @param string|null $base the branch to merge into; without it, the repository's default branch
     *
     * @throws RepositoryNotFoundException   when the GitHub CLI finds no repository for the project
     * @throws GitHubReadException           when the GitHub CLI is not installed
     * @throws PullRequestNotOpenedException when the project is on no branch, or its branch is not pushed
     */
    public function prepare(string $title, string $body, ?string $base, bool $isDraft): NewPullRequest
    {
        $repository = $this->reader->repository();

        return new NewPullRequest($repository, $this->pushedBranch(), $base ?? $repository->defaultBranch, $title, $body, $isDraft);
    }

    public function open(NewPullRequest $pullRequest): CallToolResult
    {
        $result = $this->runner->runGitHubCli($pullRequest->arguments());
        $check = $this->injectionClassifier->check($result->output);
        $this->audit($pullRequest, Outcome::OpenedOnGitHub, $result, $check);

        return $this->outputReport->result($result, $check);
    }

    public function refuse(NewPullRequest $pullRequest): void
    {
        $this->audit($pullRequest, Outcome::RefusedByUser);
    }

    /**
     * @throws PullRequestNotOpenedException
     */
    private function pushedBranch(): PushedBranch
    {
        [$remote, $branch] = $this->upstreamOfCurrentBranch();
        $url = $this->runner->runGit(['remote', 'get-url', $remote]);
        if (!$url->isSuccessful()) {
            throw new PullRequestNotOpenedException('git found no URL for the remote the current branch is pushed to: no pull request was opened.');
        }

        return PushedBranch::onRemote(trim($url->output), $branch);
    }

    /**
     * The remote and the branch on it that the current branch is pushed to: its upstream, or the branch of the same
     * name on origin.
     *
     * @return array{non-empty-string, non-empty-string}
     *
     * @throws PullRequestNotOpenedException
     */
    private function upstreamOfCurrentBranch(): array
    {
        $originBranches = 'refs/remotes/'.self::DEFAULT_REMOTE.'/';
        $result = $this->runner->runGit(['for-each-ref', '--format='.self::BRANCHES_FORMAT, self::LOCAL_BRANCHES, $originBranches]);
        if (!$result->isSuccessful()) {
            throw new PullRequestNotOpenedException('git could not read the project\'s branches: no pull request was opened. Is the project a git repository?');
        }
        $current = null;
        $references = [];
        foreach (explode("\n", rtrim($result->output, "\n")) as $line) {
            $fields = explode("\t", $line);
            if (4 !== \count($fields)) {
                continue;
            }
            if ('*' === $fields[0]) {
                $current = $fields;
            }
            $references[] = $fields[1];
        }
        if (null === $current) {
            throw new PullRequestNotOpenedException('The project is on no branch (its HEAD is detached): no pull request was opened.');
        }

        return $this->upstreamOf($current, $originBranches, $references);
    }

    /**
     * @param list<string> $branch     the current branch's fields, as BRANCHES_FORMAT lists them
     * @param list<string> $references every branch and remote branch listed
     *
     * @return array{non-empty-string, non-empty-string}
     *
     * @throws PullRequestNotOpenedException
     */
    private function upstreamOf(array $branch, string $originBranches, array $references): array
    {
        [, $reference, $remote, $remoteReference] = $branch;
        $name = substr($reference, \strlen(self::LOCAL_BRANCHES));
        $remoteName = str_starts_with($remoteReference, self::LOCAL_BRANCHES) ? substr($remoteReference, \strlen(self::LOCAL_BRANCHES)) : '';
        if ('' !== $remote && '' !== $remoteName) {
            return [$remote, $remoteName];
        }
        if ('' !== $name && \in_array($originBranches.$name, $references, true)) {
            return [self::DEFAULT_REMOTE, $name];
        }

        throw new PullRequestNotOpenedException('The current branch is not pushed: it has no upstream and is not on origin, so no pull request was opened. Airlock never pushes for you: push it first, with git push origin HEAD through run_command, then open the pull request again.');
    }

    private function audit(NewPullRequest $pullRequest, Outcome $outcome, ?CommandResult $result = null, ?OutputCheck $check = null): void
    {
        $this->auditLog->append(new AuditEntry(
            $this->clock->now(),
            $this->projectDirectory->path,
            'gh '.implode(' ', $pullRequest->arguments()),
            Verdict::AskEveryTime,
            $outcome,
            [self::REASON],
            $result?->exitCode,
            $result instanceof CommandResult && $result->hasTimedOut,
            outputCheck: $check,
            maskedSecrets: $result?->maskedSecrets,
        ));
    }
}
