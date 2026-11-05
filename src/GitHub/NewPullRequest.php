<?php

declare(strict_types=1);

namespace App\GitHub;

/**
 * A pull request to open, exactly as the user is asked about it and as the GitHub CLI is called to open it.
 */
final readonly class NewPullRequest
{
    public function __construct(
        public Repository $repository,
        public PushedBranch $head,
        public string $base,
        public string $title,
        public string $body,
        public bool $isDraft,
    ) {
    }

    /**
     * The arguments of `gh pr create`, each value joined to its option so that none can be read as an option.
     *
     * @return list<string>
     */
    public function arguments(): array
    {
        $arguments = [
            'pr', 'create',
            '--repo='.$this->repository->host.'/'.$this->repository->name,
            '--head='.$this->head->head(),
            '--base='.$this->base,
            '--title='.$this->title,
            '--body='.$this->body,
        ];
        if ($this->isDraft) {
            $arguments[] = '--draft';
        }

        return $arguments;
    }
}
