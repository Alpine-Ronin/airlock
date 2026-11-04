<?php

declare(strict_types=1);

namespace App\Sandbox;

use App\Configuration\ConfigDirectory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Where a confined command may write, what it gets a private copy of, and what it must not read or change.
 */
final readonly class SandboxPaths
{
    /** Under the home, where installs and test runs write what they download and build. */
    private const array CACHES = [
        '.cache', '.composer', '.config/composer', '.local/share/composer', '.npm', '.yarn', '.pnpm-store',
        '.local/share/pnpm', '.cargo/registry', '.cargo/git', 'go/pkg', '.gradle', '.m2', '.bundle', '.gem', '.nuget',
        'Library/Caches',
    ];

    /** Under the home, where the user's keys and tokens live. */
    private const array KEYS = [
        '.ssh', '.gnupg', '.aws', '.azure', '.kube', '.config/gcloud', '.config/gh', '.netrc', '.git-credentials',
        '.pypirc', '.docker/config.json', '.npmrc', '.composer/auth.json', '.config/composer/auth.json',
        '.cargo/credentials', '.cargo/credentials.toml', '.gem/credentials', '.vault-token',
        '.terraform.d/credentials.tfrc.json',
    ];

    /** Under Airlock's own directory, what holds its secrets. */
    private const array AIRLOCK_SECRETS = ['.env.local', '.env.*.local', 'config/secrets'];

    /** Under the project, what git runs or obeys outside the sandbox, later, as the user: directories, then files. */
    private const array GIT_CONTROL_DIRECTORIES = ['.git/hooks'];

    private const array GIT_CONTROL_FILES = ['.git/config'];

    public function __construct(
        #[Autowire(env: 'HOME')]
        private string $home,
        #[Autowire(param: 'kernel.project_dir')]
        private string $airlockDirectory,
        private ConfigDirectory $configDirectory,
        #[Autowire(env: 'default::XDG_RUNTIME_DIR')]
        private ?string $runtimeDirectory = null,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * Existing directories a command may write to besides the project.
     *
     * @return list<string>
     */
    public function writable(): array
    {
        return $this->existing(array_map(fn (string $cache): string => $this->home.'/'.$cache, self::CACHES));
    }

    /**
     * Existing directories a command gets an empty, private copy of: the temporary directories, and the runtime
     * directory, whose sockets (SSH and GPG agents, the keyring, the session bus) act with the user's credentials.
     *
     * @return list<string>
     */
    public function private(): array
    {
        $candidates = ['/tmp', '/var/tmp', sys_get_temp_dir()];
        if (null !== $this->runtimeDirectory && '' !== $this->runtimeDirectory) {
            $candidates[] = $this->runtimeDirectory;
        }

        return $this->existing($candidates);
    }

    /**
     * Existing files and directories a command must not read: the user's keys and Airlock's own configuration.
     *
     * @return list<string>
     */
    public function hidden(): array
    {
        $candidates = [$this->configDirectory->path];
        foreach (self::KEYS as $key) {
            $candidates[] = $this->home.'/'.$key;
        }
        foreach (self::AIRLOCK_SECRETS as $pattern) {
            $matches = glob($this->airlockDirectory.'/'.$pattern);
            if (false !== $matches) {
                array_push($candidates, ...$matches);
            }
        }

        return $this->existing($candidates);
    }

    /**
     * The user's known hosts, for a command lent the SSH agent: without them ssh cannot tell the real server from
     * one pretending to be it, and refuses to connect. They hold servers' public keys, nothing secret, and stay
     * read-only so that a command cannot vouch for a forged one. Null when there are none.
     */
    public function knownHosts(): ?string
    {
        $sshDirectory = realpath($this->home.'/.ssh');
        if (false === $sshDirectory || !is_file($sshDirectory.'/known_hosts')) {
            return null;
        }

        return $sshDirectory.'/known_hosts';
    }

    /**
     * Paths of the project a command may read but not change. In a git repository, those git would otherwise create
     * are made first, empty, as `git init` makes them: a path that does not exist cannot be kept read-only, and a
     * command could plant it.
     *
     * @return list<string>
     */
    public function readOnlyInProject(string $projectDirectory): array
    {
        $directories = array_map(static fn (string $control): string => $projectDirectory.'/'.$control, self::GIT_CONTROL_DIRECTORIES);
        $files = array_map(static fn (string $control): string => $projectDirectory.'/'.$control, self::GIT_CONTROL_FILES);
        if (is_dir($projectDirectory.'/.git')) {
            $this->filesystem->mkdir($directories);
            $this->filesystem->touch($files);
        }

        return $this->existing([...$directories, ...$files]);
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function existing(array $paths): array
    {
        $existing = [];
        foreach ($paths as $path) {
            $real = realpath($path);
            if (false !== $real) {
                $existing[] = $real;
            }
        }

        return array_values(array_unique($existing));
    }
}
