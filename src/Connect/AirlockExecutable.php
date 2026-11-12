<?php

declare(strict_types=1);

namespace App\Connect;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * How this installation of Airlock is started: the binary itself, or PHP with this checkout's console.
 */
final readonly class AirlockExecutable
{
    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDirectory,
    ) {
    }

    /**
     * The command line that runs one of Airlock's commands.
     *
     * @return non-empty-list<string>
     */
    public function commandLine(string ...$arguments): array
    {
        $archive = \extension_loaded('phar') ? \Phar::running(false) : '';
        if ('' !== $archive) {
            return [$archive, ...array_values($arguments)];
        }

        return [\PHP_BINARY, $this->projectDirectory.'/bin/console', ...array_values($arguments)];
    }

    /**
     * The binary this Airlock runs as, or null when it runs from a checkout, with PHP.
     */
    public function binaryPath(): ?string
    {
        $archive = \extension_loaded('phar') ? \Phar::running(false) : '';

        return '' === $archive ? null : $archive;
    }

    public function serverCommand(): ServerCommand
    {
        $archive = \extension_loaded('phar') ? \Phar::running(false) : '';
        if ('' !== $archive) {
            return new ServerCommand($archive, ['mcp:server']);
        }

        return new ServerCommand(\PHP_BINARY, [$this->projectDirectory.'/bin/console', 'mcp:server']);
    }
}
