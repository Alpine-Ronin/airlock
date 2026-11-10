<?php

declare(strict_types=1);

namespace App\Network;

/**
 * Runs inside bubblewrap, in front of a command whose network namespace holds nothing but its own loopback: it
 * listens on 127.0.0.1, where the proxy variables point, relays each connection to the proxy's socket bound in from
 * outside, and runs the command, whose exit code it returns. It starts before Symfony's kernel, whose cache the
 * sandbox keeps read-only.
 */
final class NetworkRelay
{
    private const float TICK_SECONDS = 0.05;

    private const int CHUNK_BYTES = 64 * 1024;

    /** What bubblewrap's caller gets when the relay itself cannot start, as `env` does for a command it cannot run. */
    private const int CANNOT_START = 125;

    /** @var array<int, array{resource, resource}> each connection and the socket it is relayed to, by stream */
    private array $pairs = [];

    /**
     * @param resource $listener
     */
    private function __construct(
        private $listener,
        private readonly string $proxySocket,
    ) {
    }

    /**
     * @param list<string> $arguments the proxy's socket, the port to listen on, `--`, then the command
     */
    public static function run(array $arguments): int
    {
        $command = \array_slice($arguments, 3);
        $port = $arguments[1] ?? '';
        if (!isset($arguments[0]) || '--' !== ($arguments[2] ?? null) || [] === $command || 1 !== preg_match('/^[1-9][0-9]{0,4}$/', $port)) {
            fwrite(\STDERR, "Airlock: network-relay <proxy socket> <port> -- <command>...\n");

            return self::CANNOT_START;
        }
        try {
            $relay = new self(Streams::server('tcp://127.0.0.1:'.$port), $arguments[0]);
        } catch (NetworkStreamException $e) {
            fwrite(\STDERR, 'Airlock: '.$e->getMessage()."\n");

            return self::CANNOT_START;
        }

        return $relay->runCommand($command);
    }

    /**
     * @param non-empty-list<string> $command
     */
    private function runCommand(array $command): int
    {
        $process = proc_open($command, [0 => \STDIN, 1 => \STDOUT, 2 => \STDERR], $pipes);
        if (!\is_resource($process)) {
            fwrite(\STDERR, \sprintf("Airlock: %s cannot be started.\n", $command[0]));

            return self::CANNOT_START;
        }
        $status = proc_get_status($process);
        while ($status['running']) {
            $this->tick();
            $status = proc_get_status($process);
        }
        proc_close($process);
        if ($status['signaled']) {
            return 128 + $status['termsig'];
        }

        return $status['exitcode'];
    }

    private function tick(): void
    {
        $streams = [$this->listener];
        foreach ($this->pairs as $pair) {
            $streams[] = $pair[0];
        }
        try {
            $readable = Streams::readable($streams, self::TICK_SECONDS);
        } catch (NetworkStreamException) {
            // Nothing can be waited on this time: the command keeps running, and the next tick tries again.
            return;
        }
        foreach ($readable as $stream) {
            if ($stream === $this->listener) {
                $this->accept();

                continue;
            }
            $this->relay($stream);
        }
    }

    private function accept(): void
    {
        try {
            $connection = Streams::accept($this->listener);
        } catch (NetworkStreamException) {
            // The command gave up on this connection before it was accepted: there is nothing to relay.
            return;
        }
        try {
            $proxy = Streams::client('unix://'.$this->proxySocket, 5.0);
        } catch (NetworkStreamException) {
            // Without the proxy the command has no network: closing its connection tells it so.
            fclose($connection);

            return;
        }
        $this->pairs[get_resource_id($connection)] = [$connection, $proxy];
        $this->pairs[get_resource_id($proxy)] = [$proxy, $connection];
    }

    /**
     * @param resource $stream
     */
    private function relay($stream): void
    {
        $pair = $this->pairs[get_resource_id($stream)] ?? null;
        if (null === $pair) {
            return;
        }
        [$from, $to] = $pair;
        try {
            $bytes = Streams::read($from, self::CHUNK_BYTES);
            if ('' !== $bytes) {
                Streams::write($to, $bytes);

                return;
            }
        } catch (NetworkStreamException) {
            // Either side failed: the connection ends, as it would without the relay.
        }
        unset($this->pairs[get_resource_id($from)], $this->pairs[get_resource_id($to)]);
        fclose($from);
        fclose($to);
    }
}
