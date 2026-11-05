<?php

declare(strict_types=1);

namespace App\Sandbox;

/**
 * A path git follows to its hooks and configuration is a symbolic link in a directory a command may write to: the
 * sandbox can keep the link's target read-only, not the link, so a command could replace it with one of its own.
 */
final class ReplaceableGitLinkException extends \RuntimeException
{
    public function __construct(string $link)
    {
        parent::__construct(\sprintf(
            '%s is a symbolic link, which the sandbox cannot keep a command from replacing: git would then run hooks or read configuration the command made. Make it a real file or directory (to share hooks, set core.hooksPath instead).',
            $link,
        ));
    }
}
