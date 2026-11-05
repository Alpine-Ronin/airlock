<?php

declare(strict_types=1);

namespace App\Mcp;

use App\GitHub\GitHubReadException;
use App\GitHub\PullRequestNotOpenedException;
use App\GitHub\PullRequestOpener;
use App\GitHub\RepositoryNotFoundException;
use App\Policy\Verdict;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server\RequestContext;

/**
 * Opens a pull request once the user has allowed it, asked every time like `git push`: no grant ever covers it. The
 * signed state names the exact gh call shown, so an answer cannot open a pull request other than the one the user saw.
 */
#[McpTool(
    name: 'github_open_pr',
    description: 'Open a GitHub pull request from the current branch, once the user allows it: asked every time, never granted. Airlock runs the user\'s GitHub CLI for you, outside the sandbox, and never pushes: push the branch first. What gh printed, the pull request\'s URL, comes back as data, checked for prompt injection.',
)]
final readonly class GitHubOpenPullRequestTool
{
    public function __construct(
        private PullRequestOpener $opener,
        private ApprovalForm $approvalForm,
        private Approvals $approvals,
    ) {
    }

    /**
     * @param string      $title the pull request's title, on one line
     * @param string      $body  its description, in Markdown
     * @param string|null $base  the branch to merge into; without it, the repository's default branch
     * @param bool        $draft whether to open it as a draft
     */
    public function __invoke(string $title, string $body, RequestContext $context, ?string $base = null, bool $draft = false): CallToolResult|InputRequiredResult
    {
        $invalid = $this->invalidInput($title, $base);
        if (null !== $invalid) {
            return CallToolResult::error([new TextContent($invalid.': no pull request was opened.')]);
        }

        try {
            $pullRequest = $this->opener->prepare($title, $body, $base, $draft);
        } catch (RepositoryNotFoundException $e) {
            return $e->report;
        } catch (GitHubReadException|PullRequestNotOpenedException $e) {
            return CallToolResult::error([new TextContent($e->getMessage())]);
        }

        $call = json_encode($pullRequest->arguments(), \JSON_THROW_ON_ERROR);
        $decision = $this->approvals->answer($context, $call, Verdict::AskEveryTime);
        if (!$decision instanceof Decision) {
            return $this->approvals->ask($context, $call, $this->approvalForm->pullRequest($pullRequest));
        }
        if (Decision::Refuse === $decision) {
            $this->opener->refuse($pullRequest);

            return CallToolResult::error([new TextContent('Refused by the user: no pull request was opened.')]);
        }

        return $this->opener->open($pullRequest);
    }

    /**
     * Why the title or base cannot be shown on one line of the form, or null when they can.
     */
    private function invalidInput(string $title, ?string $base): ?string
    {
        if ('' === trim($title) || 1 === preg_match('/[\r\n]/', $title)) {
            return 'The title must be one line, not empty';
        }
        if (null !== $base && ('' === trim($base) || 1 === preg_match('/\s/', $base))) {
            return 'The base must be a branch name';
        }

        return null;
    }
}
