<?php

declare(strict_types=1);

namespace App\Configuration;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Airlock's own directory, out of every command's reach: the policy, the grants and the audit log live here.
 */
final readonly class ConfigDirectory
{
    public string $path;

    public function __construct(
        #[Autowire(env: 'HOME')]
        string $home,
        #[Autowire(env: 'default::XDG_CONFIG_HOME')]
        ?string $configHome = null,
    ) {
        $base = null === $configHome || '' === $configHome ? rtrim($home, '/').'/.config' : $configHome;
        $this->path = rtrim($base, '/').'/airlock';
    }

    /**
     * The directory for one project: named after it so it can be found by hand, and keyed by its full path.
     */
    public function forProject(string $projectDirectory): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($projectDirectory)) ?? 'project';

        return \sprintf('%s/projects/%s-%s', $this->path, $name, substr(hash('sha256', $projectDirectory), 0, 12));
    }
}
