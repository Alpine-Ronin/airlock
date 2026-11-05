<?php

declare(strict_types=1);

namespace App\GitHub;

/**
 * The branch a pull request is opened from, as it is on GitHub: whose fork, which branch.
 */
final readonly class PushedBranch
{
    /**
     * @param non-empty-string $owner
     * @param non-empty-string $name
     */
    public function __construct(
        public string $owner,
        public string $name,
    ) {
    }

    /**
     * The branch pushed to the remote at $remoteUrl, whose path names its owner: `git@github.com:owner/name.git`,
     * `https://github.com/owner/name`, `ssh://git@github.com/owner/name.git`.
     *
     * @param non-empty-string $name
     *
     * @throws PullRequestNotOpenedException when the URL names no owner
     */
    public static function onRemote(string $remoteUrl, string $name): self
    {
        if (1 !== preg_match('#[/:]([^/:\s]+)/[^/\s]+/?$#', $remoteUrl, $match)) {
            throw new PullRequestNotOpenedException('The remote the current branch is pushed to names no GitHub repository: no pull request was opened.');
        }

        return new self($match[1], $name);
    }

    /**
     * As `gh pr create --head` takes it, which names the fork too: the pull request is opened from this branch and gh
     * never pushes one.
     */
    public function head(): string
    {
        return $this->owner.':'.$this->name;
    }
}
