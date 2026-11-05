<?php

declare(strict_types=1);

namespace App\Sandbox;

use App\Configuration\ConfigDirectory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

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

    /** Under a git directory, what git runs or obeys outside the sandbox, later, as the user: directories, then files. */
    private const array GIT_CONTROL_DIRECTORIES = ['hooks'];

    private const array GIT_CONTROL_FILES = ['config', 'config.worktree'];

    /**
     * Under a git directory, where git takes its hooks and configuration from instead when it exists. A missing one is
     * made naming the git directory itself, `.`: git refuses an empty one.
     */
    private const string GIT_COMMON_DIRECTORY_FILE = 'commondir';

    /** How a `.git` file starts: a linked worktree, or a repository made with `--separate-git-dir`. */
    private const string GIT_FILE_POINTER = 'gitdir: ';

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
     * The first key store, Airlock directory or Airlock installation the project overlaps, existing or not, or null
     * when it overlaps none. A sandbox lets a command write anywhere in the project, so it cannot keep these out of
     * reach there: a command could create a missing `~/.ssh/config` or Airlock's `.env.local`, change Airlock's own
     * code, or rename the directory above a hidden one and write in it. A project inside a key store or Airlock's
     * directory would be hidden from its own commands.
     */
    public function overlappedBy(string $projectDirectory): ?string
    {
        // Airlock's installation is not hidden, only its secrets: a project inside it is fine.
        $installation = $this->resolved($this->airlockDirectory);
        if ($this->isWithin($installation, $projectDirectory)) {
            return $installation;
        }
        $hiddenWhole = [$this->resolved($this->configDirectory->path)];
        $home = $this->resolved($this->home);
        foreach (self::KEYS as $key) {
            $hiddenWhole[] = $this->resolved($home.'/'.$key);
        }
        foreach ($hiddenWhole as $hidden) {
            if ($this->isWithin($hidden, $projectDirectory) || $this->isWithin($projectDirectory, $hidden)) {
                return $hidden;
            }
        }

        return null;
    }

    private function resolved(string $path): string
    {
        $real = realpath($path);

        return false === $real ? $path : $real;
    }

    private function isWithin(string $path, string $directory): bool
    {
        return $path === $directory || str_starts_with($path, rtrim($directory, '/').'/');
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
     * Paths of the project's repository a command may read but not change. In a git directory, those git would
     * otherwise create are made first, as `git init` makes them: a path that does not exist cannot be kept read-only,
     * and a command could plant it. When `.git` is a file, it is kept too, with the git directory it points to and the
     * common one that directory shares, wherever they are: a command could otherwise point it at a git directory of its
     * own making. Any directory a `.git` file or a `commondir` points to keeps what it already has read-only, even when
     * it no longer looks like a git directory: a command could otherwise strip its HEAD in one run and plant a hook in
     * the next. Missing paths are made only in a git directory.
     *
     * @return list<string>
     */
    public function readOnlyInProject(string $projectDirectory): array
    {
        $dotGit = $projectDirectory.'/.git';
        if (!is_dir($dotGit) && !is_file($dotGit)) {
            return [];
        }
        $isGitFile = is_file($dotGit);
        $readOnly = $isGitFile ? [$dotGit] : [];
        $gitDirectories = $isGitFile ? $this->gitDirectoriesPointedToBy($dotGit) : $this->withCommonDirectory($dotGit);
        foreach ($gitDirectories as $gitDirectory) {
            $isProjectGitDirectory = !$isGitFile && $gitDirectory === $dotGit;
            if ($isProjectGitDirectory || $this->isGitDirectory($gitDirectory)) {
                $this->makeGitControl($gitDirectory);
            }
            array_push($readOnly, ...$this->gitControl($gitDirectory));
        }

        return $this->existing($readOnly);
    }

    private function makeGitControl(string $gitDirectory): void
    {
        $this->filesystem->mkdir($this->gitControlDirectories($gitDirectory));
        $this->filesystem->touch($this->gitControlFiles($gitDirectory));
        $commonDirectoryFile = $gitDirectory.'/'.self::GIT_COMMON_DIRECTORY_FILE;
        if (!file_exists($commonDirectoryFile)) {
            $this->filesystem->dumpFile($commonDirectoryFile, ".\n");
        }
    }

    /**
     * @return list<string>
     */
    private function gitControl(string $gitDirectory): array
    {
        return $this->existing([
            ...$this->gitControlDirectories($gitDirectory),
            ...$this->gitControlFiles($gitDirectory),
            $gitDirectory.'/'.self::GIT_COMMON_DIRECTORY_FILE,
        ]);
    }

    /**
     * @return list<string>
     */
    private function gitControlDirectories(string $gitDirectory): array
    {
        return array_map(static fn (string $control): string => $gitDirectory.'/'.$control, self::GIT_CONTROL_DIRECTORIES);
    }

    /**
     * @return list<string>
     */
    private function gitControlFiles(string $gitDirectory): array
    {
        return array_map(static fn (string $control): string => $gitDirectory.'/'.$control, self::GIT_CONTROL_FILES);
    }

    /**
     * The existing directory a `.git` file points to, then its common directory, as git resolves them: each relative
     * to the file that names it.
     *
     * @return list<string>
     */
    private function gitDirectoriesPointedToBy(string $gitFile): array
    {
        $pointer = $this->filesystem->readFile($gitFile);
        if (!str_starts_with($pointer, self::GIT_FILE_POINTER)) {
            return [];
        }
        $gitDirectory = $this->resolvedFrom(\dirname($gitFile), substr($pointer, \strlen(self::GIT_FILE_POINTER)));
        if (null === $gitDirectory) {
            return [];
        }

        return $this->withCommonDirectory($gitDirectory);
    }

    /**
     * The git directory, then the existing common directory its `commondir` names, if any: git takes its hooks and
     * configuration from there.
     *
     * @return non-empty-list<string>
     */
    private function withCommonDirectory(string $gitDirectory): array
    {
        $commonDirectoryFile = $gitDirectory.'/'.self::GIT_COMMON_DIRECTORY_FILE;
        if (!is_file($commonDirectoryFile)) {
            return [$gitDirectory];
        }
        $commonDirectory = $this->resolvedFrom($gitDirectory, $this->filesystem->readFile($commonDirectoryFile));
        if (null === $commonDirectory) {
            return [$gitDirectory];
        }

        return [$gitDirectory, $commonDirectory];
    }

    /**
     * Whether $directory looks like a git directory, so that the paths git would make in it may be made first: it has a
     * HEAD, the first thing git looks for in one.
     */
    private function isGitDirectory(string $directory): bool
    {
        return is_file($directory.'/HEAD');
    }

    /**
     * The existing directory a line of a git file names, relative to $base unless absolute, or null when it names
     * none.
     */
    private function resolvedFrom(string $base, string $line): ?string
    {
        $path = rtrim($line, "\r\n");
        if ('' === $path) {
            return null;
        }
        $directory = Path::makeAbsolute($path, $base);

        return is_dir($directory) ? $directory : null;
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
