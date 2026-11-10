<?php

declare(strict_types=1);

namespace App\Execution;

use App\Connect\AirlockExecutable;
use App\Network\NetworkSettings;
use App\Network\ProxyEndpoint;
use App\Sandbox\Sandbox;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

/**
 * Starts `airlock network-proxy` for one command, in a directory of its own that only the user can enter: its socket
 * and the file it logs its decisions to.
 */
final readonly class RegistriesProxy
{
    private const float START_SECONDS = 10.0;

    public function __construct(
        private AirlockExecutable $airlockExecutable,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @throws RegistriesProxyUnavailableException
     */
    public function start(Sandbox $sandbox, NetworkSettings $settings): RunningProxy
    {
        $directory = \sprintf('%s/airlock-proxy-%s', rtrim(sys_get_temp_dir(), '/'), bin2hex(random_bytes(8)));
        $this->filesystem->mkdir($directory, 0o700);
        $log = $directory.'/decisions.jsonl';
        $listen = $sandbox->proxyListenAddress($directory);
        $arguments = ['network-proxy', '--listen', $listen->option, '--log', $log];
        foreach ($settings->allowedHosts->authorities() as $authority) {
            $arguments[] = '--allow';
            $arguments[] = $authority;
        }
        foreach ($settings->localPorts as $port) {
            $arguments[] = '--local-port';
            $arguments[] = (string) $port;
        }
        $process = new Process($this->airlockExecutable->commandLine(...$arguments), timeout: self::START_SECONDS);
        try {
            $process->start();
            $process->waitUntil(static fn (string $type, string $output): bool => Process::OUT === $type && str_contains($output, "\n"));
            // Started, it serves as long as the command runs: the command's own time limit applies.
            $process->setTimeout(null);
            $endpoint = ProxyEndpoint::fromAnnounced(trim(explode("\n", $process->getOutput(), 2)[0]))->withLocalPorts($settings->localPorts);
        } catch (ProcessException|\InvalidArgumentException $e) {
            $process->stop(0);
            $this->filesystem->remove($directory);
            $reason = trim($process->getErrorOutput());

            throw new RegistriesProxyUnavailableException(\sprintf('Airlock cannot start the proxy to the package registries: %s', '' === $reason ? $e->getMessage() : $reason), $e->getCode(), previous: $e);
        }

        return new RunningProxy($process, $endpoint, $directory, $log, $this->filesystem);
    }
}
