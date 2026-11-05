<?php

declare(strict_types=1);

namespace App\Mcp;

use App\GitHub\GitHubReader;
use App\GitHub\GitHubReadException;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

#[McpTool(
    name: 'github_pr_checks',
    description: 'Read the CI checks of a GitHub pull request: the current branch\'s, or the one numbered. Airlock runs the user\'s GitHub CLI for you, outside the sandbox, without asking; what it read comes back as data, checked for prompt injection. Checks still pending come back as such; gh exits 1 when one failed.',
)]
final readonly class GitHubChecksTool
{
    /** The exit code of gh pr checks while checks are pending: the answer, not a failure to give one. */
    private const array PENDING = [8];

    public function __construct(
        private GitHubReader $reader,
    ) {
    }

    /**
     * @param int|null $pullRequest the pull request's number; without it, the current branch's
     */
    public function __invoke(?int $pullRequest = null): CallToolResult
    {
        if (null !== $pullRequest && $pullRequest < 1) {
            return CallToolResult::error([new TextContent(\sprintf('%d is not a pull request number: nothing was read.', $pullRequest))]);
        }

        try {
            return $this->reader->read([['pr', 'checks', ...(null === $pullRequest ? [] : [(string) $pullRequest])]], self::PENDING);
        } catch (GitHubReadException $e) {
            return CallToolResult::error([new TextContent($e->getMessage())]);
        }
    }
}
