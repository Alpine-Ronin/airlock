<?php

declare(strict_types=1);

namespace App\GitHub;

/**
 * A GitHub repository as the GitHub CLI names it: on which host, which one, and the branch it merges into by default.
 */
final readonly class Repository
{
    /**
     * @param non-empty-string $host          github.com, or a GitHub Enterprise host
     * @param non-empty-string $name          owner/name
     * @param non-empty-string $defaultBranch
     */
    public function __construct(
        public string $host,
        public string $name,
        public string $defaultBranch,
    ) {
    }

    /**
     * @throws GitHubReadException when the URL names no repository, or the repository has no branch yet
     */
    public static function fromUrl(string $url, string $defaultBranch): self
    {
        if (1 !== preg_match('#^https://([^/\s]+)/([^/\s]+/[^/\s]+)$#', $url, $match)) {
            throw new GitHubReadException(\sprintf('The GitHub CLI gave no repository URL: %s', $url));
        }
        if ('' === $defaultBranch) {
            throw new GitHubReadException(\sprintf('The repository %s has no branch yet: there is nothing to open a pull request against.', $match[2]));
        }

        return new self($match[1], $match[2], $defaultBranch);
    }
}
