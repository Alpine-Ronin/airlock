<?php

declare(strict_types=1);

namespace App\Network;

/**
 * Runs inside bubblewrap, in front of a command whose network namespace holds nothing but its own loopback: it
 * listens on 127.0.0.1, where the proxy variables point, relays each connection to the proxy's socket bound in from
 * outside, and runs the command, whose exit code it returns. It listens on each local port the user opened too, and
 * asks the proxy for that port on this machine. It starts before Symfony's kernel, whose cache the sandbox keeps
 * read-only.
 */
final class NetworkRelay
{
    private const float TICK_SECONDS = 0.05;

    private const int CHUNK_BYTES = 64 * 1024;

    /** What bubblewrap's caller gets when the relay itself cannot start, as `env` does for a command it cannot run. */
    private const int CANNOT_START = 125;

    private const float HANDSHAKE_SECONDS = 5.0;

    /** @var array<int, array{resource, resource}> each connection and the socket it is relayed to, by stream */
    private array $pairs = [];

    /**
     * @param array<int, array{resource, int|null}> $listeners each listener, by stream, with the local port it stands
     *                                                         for, or null for the one the proxy variables point to
     */
    private function __construct(
        private array $listeners,
        private readonly string $proxySocket,
    ) {
    }

    /**
     * @param list<string> $arguments the proxy's socket, the port to listen on, the local ports, `--`, then the command
     */
    public static function run(array $arguments): int
    {
        $separator = array_search('--', $arguments, true);
        $ports = false === $separator ? [] : \array_slice($arguments, 1, $separator - 1);
        $command = false === $separator ? [] : \array_slice($arguments, $separator + 1);
        if (!isset($arguments[0]) || [] === $ports || [] === $command || !self::arePorts($ports)) {
            fwrite(\STDERR, "Airlock: network-relay <proxy socket> <port> [<local port>...] -- <command>...\n");

            return self::CANNOT_START;
        }
        $listeners = [];
        try {
            foreach ($ports as $index => $port) {
                $listener = Streams::server('tcp://127.0.0.1:'.$port);
                $listeners[get_resource_id($listener)] = [$listener, 0 === $index ? null : (int) $port];
            }
        } catch (NetworkStreamException $e) {
            fwrite(\STDERR, 'Airlock: '.$e->getMessage()."\n");

            return self::CANNOT_START;
        }

        return new self($listeners, $arguments[0])->runCommand($command);
    }

    /**
     * @param list<string> $values
     */
    private static function arePorts(array $values): bool
    {
        return array_all($values, static fn (string $value): bool => 1 === preg_match('/^[1-9][0-9]{0,4}$/', $value) && (int) $value <= 65535);
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
        $streams = [];
        foreach ($this->listeners as [$listener]) {
            $streams[] = $listener;
        }
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
            $listener = $this->listeners[get_resource_id($stream)] ?? null;
            if (null !== $listener) {
                $this->accept(...$listener);

                continue;
            }
            $this->relay($stream);
        }
    }

    /**
     * @param resource $listener
     */
    private function accept($listener, ?int $localPort): void
    {
        try {
            $connection = Streams::accept($listener);
        } catch (NetworkStreamException) {
            // The command gave up on this connection before it was accepted: there is nothing to relay.
            return;
        }
        try {
            $proxy = Streams::client('unix://'.$this->proxySocket, self::HANDSHAKE_SECONDS);
            if (null !== $localPort) {
                $this->openLocalPort($proxy, $localPort);
            }
        } catch (NetworkStreamException) {
            // Without the proxy, or the port it was asked for, the command has no network: closing tells it so.
            fclose($connection);

            return;
        }
        $this->pairs[get_resource_id($connection)] = [$connection, $proxy];
        $this->pairs[get_resource_id($proxy)] = [$proxy, $connection];
    }

    /**
     * Asks the proxy for a port on this machine, and reads its answer, so the command's own protocol starts clean.
     *
     * @param resource $proxy
     *
     * @throws NetworkStreamException
     */
    private function openLocalPort($proxy, int $localPort): void
    {
        try {
            $this->askForLocalPort($proxy, $localPort);
        } catch (NetworkStreamException $e) {
            fclose($proxy);

            throw $e;
        }
    }

    /**
     * @param resource $proxy
     *
     * @throws NetworkStreamException
     */
    private function askForLocalPort($proxy, int $localPort): void
    {
        Streams::write($proxy, \sprintf("CONNECT localhost:%d HTTP/1.1\r\n\r\n", $localPort));
        $answer = '';
        while (!str_contains($answer, "\r\n\r\n")) {
            if ([] === Streams::readable([$proxy], self::HANDSHAKE_SECONDS)) {
                throw new NetworkStreamException(\sprintf('The proxy did not answer for localhost:%d.', $localPort));
            }
            $bytes = Streams::read($proxy, 1);
            if ('' === $bytes) {
                throw new NetworkStreamException(\sprintf('The proxy closed the connection to localhost:%d.', $localPort));
            }
            $answer .= $bytes;
        }
        if (!str_starts_with($answer, 'HTTP/1.1 200 ')) {
            throw new NetworkStreamException(\sprintf('The proxy refused localhost:%d.', $localPort));
        }
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
