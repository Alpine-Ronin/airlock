<?php

declare(strict_types=1);

namespace App\GitHub;

/**
 * GitHub could not be read: the GitHub CLI is missing, or it found no pull request to read.
 */
final class GitHubReadException extends \RuntimeException
{
}
