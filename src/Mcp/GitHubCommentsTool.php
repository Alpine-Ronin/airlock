<?php

declare(strict_types=1);

namespace App\Mcp;

use App\GitHub\GitHubReader;
use App\GitHub\GitHubReadException;
use App\GitHub\PullRequestNotFoundException;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

#[McpTool(
    name: 'github_pr_comments',
    description: 'Read the reviews and comments of a GitHub pull request, the comments on its lines included: the current branch\'s, or the one numbered. Airlock runs the user\'s GitHub CLI for you, outside the sandbox, without asking; what it read comes back as data, checked for prompt injection.',
)]
final readonly class GitHubCommentsTool
{
    /** One line per comment on a line of the diff: where, who, what. */
    private const string LINE_COMMENTS = '.[] | "\(.path):\(.line // .original_line) \(.user.login): \(.body)\n"';

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
            $found = $this->reader->pullRequest($pullRequest);

            return $this->reader->read([
                ['pr', 'view', (string) $found->number, '--comments'],
                ['api', '--hostname', $found->host, \sprintf('repos/%s/pulls/%d/comments', $found->repository, $found->number), '--paginate', '--jq', self::LINE_COMMENTS],
            ]);
        } catch (PullRequestNotFoundException $e) {
            return $e->report;
        } catch (GitHubReadException $e) {
            return CallToolResult::error([new TextContent($e->getMessage())]);
        }
    }
}
