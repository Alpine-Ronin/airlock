<?php

declare(strict_types=1);

namespace App\GitHub;

/**
 * A pull request as its URL names it: on which GitHub host, in which repository, with which number.
 */
final readonly class PullRequest
{
    /**
     * @param non-empty-string $host       github.com, or a GitHub Enterprise host
     * @param non-empty-string $repository owner/name
     * @param positive-int     $number
     */
    public function __construct(
        public string $host,
        public string $repository,
        public int $number,
    ) {
    }

    /**
     * @throws GitHubReadException when the URL names no pull request
     */
    public static function fromUrl(string $url): self
    {
        if (1 !== preg_match('#^https://([^/\s]+)/([^/\s]+/[^/\s]+)/pull/([1-9][0-9]*)$#', $url, $match)) {
            throw new GitHubReadException(\sprintf('The GitHub CLI gave no pull request URL: %s', $url));
        }

        return new self($match[1], $match[2], max(1, (int) $match[3]));
    }
}
