<?php

declare(strict_types=1);

namespace App\Sandbox;

use App\Execution\ProjectDirectory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Finds the sandbox this machine can run, once, and says why when there is none.
 */
final class SandboxDetector
{
    private ?Sandbox $detected = null;

    public function __construct(
        private readonly SandboxPaths $paths,
        private readonly ProjectDirectory $projectDirectory,
        #[Autowire(env: 'enum:App\Sandbox\SandboxMode:AIRLOCK_SANDBOX')]
        private readonly SandboxMode $mode,
        private readonly ExecutableFinder $executableFinder = new ExecutableFinder(),
        private readonly string $operatingSystemFamily = \PHP_OS_FAMILY,
    ) {
    }

    public function detect(): Sandbox
    {
        return $this->detected ??= $this->find();
    }

    private function find(): Sandbox
    {
        if (SandboxMode::Off === $this->mode) {
            return new NoSandbox('turned off with AIRLOCK_SANDBOX=off');
        }
        $held = $this->paths->heldBy($this->projectDirectory->path);
        if (null !== $held) {
            return new NoSandbox(\sprintf('the project holds %s, which no sandbox can keep a command from changing there; open a project inside your home instead', $held));
        }
        $cutsNetwork = SandboxMode::Strict === $this->mode;

        return match ($this->operatingSystemFamily) {
            'Linux' => $this->bubblewrap($cutsNetwork),
            'Darwin' => $this->sandboxExec($cutsNetwork),
            default => new NoSandbox(\sprintf('no sandbox is known on %s', $this->operatingSystemFamily)),
        };
    }

    private function bubblewrap(bool $cutsNetwork): Sandbox
    {
        $executable = $this->executableFinder->find('bwrap');
        if (null === $executable) {
            return new NoSandbox('bubblewrap is not installed; install it to keep commands in the project');
        }

        $sandbox = new Bubblewrap($this->paths, $cutsNetwork, $executable);
        // Installed is not working: unprivileged user namespaces can be turned off, and then every command fails.
        $failure = $this->probe($sandbox);

        return null === $failure ? $sandbox : new NoSandbox(\sprintf('bubblewrap cannot start here: %s', $failure));
    }

    private function sandboxExec(bool $cutsNetwork): Sandbox
    {
        if (null === $this->executableFinder->find('sandbox-exec')) {
            return new NoSandbox('sandbox-exec is missing from this Mac');
        }

        $sandbox = new SandboxExec($this->paths, $cutsNetwork);
        $failure = $this->probe($sandbox);

        return null === $failure ? $sandbox : new NoSandbox(\sprintf('sandbox-exec cannot start here: %s', $failure));
    }

    /**
     * Why running `true` in the sandbox fails, or null when it runs.
     */
    private function probe(Sandbox $sandbox): ?string
    {
        $process = new Process($sandbox->confine(['true'], sys_get_temp_dir()), timeout: 10);
        try {
            $process->run();
        } catch (ProcessException $e) {
            return $e->getMessage();
        }
        if ($process->isSuccessful()) {
            return null;
        }
        $error = trim($process->getErrorOutput());

        return '' === $error ? \sprintf('exit code %d', $process->getExitCode() ?? -1) : explode("\n", $error)[0];
    }
}
