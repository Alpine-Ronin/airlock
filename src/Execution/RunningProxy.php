<?php

declare(strict_types=1);

namespace App\Execution;

use App\Network\ProxyDecision;
use App\Network\ProxyEndpoint;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * The proxy serving one command, until it is stopped: then it says which hosts it blocked.
 */
final readonly class RunningProxy
{
    private const float STOP_SECONDS = 5.0;

    public function __construct(
        private Process $process,
        public ProxyEndpoint $endpoint,
        private string $directory,
        private string $log,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * Stops the proxy and removes its directory.
     *
     * @return list<string> the hosts it blocked, as host:port, each once, in the order they were tried
     */
    public function stop(): array
    {
        $this->process->stop(self::STOP_SECONDS, \SIGTERM);
        $blocked = $this->blocked();
        $this->filesystem->remove($this->directory);

        return $blocked;
    }

    /**
     * @return list<string>
     */
    private function blocked(): array
    {
        if (!is_file($this->log)) {
            return [];
        }
        $lines = file($this->log, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (false === $lines) {
            return [];
        }
        $blocked = [];
        foreach ($lines as $line) {
            $authority = $this->blockedAuthority($line);
            if (null !== $authority && !\in_array($authority, $blocked, true)) {
                $blocked[] = $authority;
            }
        }

        return $blocked;
    }

    /**
     * The host and port a decision line blocked, or null when it blocked none.
     */
    private function blockedAuthority(string $line): ?string
    {
        $decision = json_decode($line, true);
        if (!\is_array($decision) || !\is_string($decision['host'] ?? null) || !\is_int($decision['port'] ?? null) || !\is_string($decision['decision'] ?? null)) {
            return null;
        }
        $outcome = ProxyDecision::tryFrom($decision['decision']);
        if (ProxyDecision::NotListed !== $outcome && ProxyDecision::PrivateAddress !== $outcome) {
            return null;
        }
        $host = str_contains($decision['host'], ':') ? \sprintf('[%s]', $decision['host']) : $decision['host'];

        return \sprintf('%s:%d', $host, $decision['port']);
    }
}
