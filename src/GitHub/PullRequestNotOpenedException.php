<?php

declare(strict_types=1);

namespace App\GitHub;

/**
 * No pull request can be opened from the project as it is: it is on no branch, or its branch is not pushed.
 */
final class PullRequestNotOpenedException extends \RuntimeException
{
}
