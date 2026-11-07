<?php

declare(strict_types=1);

namespace App\Grant;

/**
 * The usual commands of a kind of project that `airlock grants --preset` allows always in one step: tests, linters and
 * static analysis. None installs, deletes, pushes or downloads: an install runs code from the network.
 */
enum Preset: string
{
    case Php = 'php';
    case Node = 'node';
    case Python = 'python';

    /**
     * Each command as the words a grant starts with: it also allows longer ones (`vendor/bin/phpunit --filter x`).
     *
     * @return non-empty-list<non-empty-list<string>>
     */
    public function commands(): array
    {
        return match ($this) {
            self::Php => [
                ['vendor/bin/phpunit'], ['php', 'bin/phpunit'], ['vendor/bin/phpstan'], ['vendor/bin/php-cs-fixer'],
                ['vendor/bin/rector', 'process', '--dry-run'], ['composer', 'test'], ['composer', 'check'],
                ['composer', 'validate'],
            ],
            self::Node => [
                ['npm', 'test'], ['npm', 'run', 'test'], ['npm', 'run', 'lint'], ['npm', 'run', 'build'],
                ['npm', 'run', 'typecheck'],
            ],
            self::Python => [['pytest'], ['ruff', 'check'], ['ruff', 'format', '--check'], ['mypy']],
        };
    }

    /**
     * The files that say a project is of this kind.
     *
     * @return non-empty-list<string>
     */
    public function markers(): array
    {
        return match ($this) {
            self::Php => ['composer.json'],
            self::Node => ['package.json'],
            self::Python => ['pyproject.toml', 'requirements.txt', 'setup.py'],
        };
    }
}
