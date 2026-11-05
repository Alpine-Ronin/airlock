<?php

declare(strict_types=1);

namespace App\GitHub;

use Mcp\Schema\Result\CallToolResult;

/**
 * The GitHub CLI found no such pull request: its answer, which can name the branch, comes checked and marked as data.
 */
final class PullRequestNotFoundException extends \RuntimeException
{
    public function __construct(
        public readonly CallToolResult $report,
    ) {
        parent::__construct('The GitHub CLI found no such pull request.');
    }
}
