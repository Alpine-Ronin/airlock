<?php

declare(strict_types=1);

namespace App\Network;

/**
 * PHP's socket functions say why they failed only in a warning: these catch it and throw it as an exception instead,
 * so a connection the peer reset is a failure to handle, not an error that stops the proxy.
 */
final class Streams
{
    /**
     * @return resource
     *
     * @throws NetworkStreamException
     */
    public static function server(string $address)
    {
        [$server, $warning] = self::call(static fn (): mixed => stream_socket_server($address, $code, $message));
        if (!\is_resource($server)) {
            throw new NetworkStreamException(\sprintf('Airlock cannot listen on %s: %s', $address, $warning ?? 'no reason given'));
        }

        return $server;
    }

    /**
     * @return resource
     *
     * @throws NetworkStreamException
     */
    public static function client(string $address, float $timeoutSeconds)
    {
        [$client, $warning] = self::call(static fn (): mixed => stream_socket_client($address, $code, $message, $timeoutSeconds));
        if (!\is_resource($client)) {
            throw new NetworkStreamException(\sprintf('Airlock cannot connect to %s: %s', $address, $warning ?? 'no reason given'));
        }

        return $client;
    }

    /**
     * @param resource $listener
     *
     * @return resource
     *
     * @throws NetworkStreamException
     */
    public static function accept($listener)
    {
        [$connection, $warning] = self::call(static fn (): mixed => stream_socket_accept($listener, 1.0));
        if (!\is_resource($connection)) {
            throw new NetworkStreamException(\sprintf('Airlock cannot accept a connection: %s', $warning ?? 'no reason given'));
        }

        return $connection;
    }

    /**
     * What the stream holds, up to $length bytes; an empty string once the peer closed it.
     *
     * @param resource     $stream
     * @param positive-int $length
     *
     * @throws NetworkStreamException
     */
    public static function read($stream, int $length): string
    {
        [$bytes, $warning] = self::call(static fn (): mixed => fread($stream, $length));
        if (!\is_string($bytes) || null !== $warning) {
            throw new NetworkStreamException(\sprintf('Airlock cannot read a connection: %s', $warning ?? 'no reason given'));
        }

        return $bytes;
    }

    /**
     * @param resource $stream
     *
     * @throws NetworkStreamException
     */
    public static function write($stream, string $bytes): void
    {
        while ('' !== $bytes) {
            [$written, $warning] = self::call(static fn (): mixed => fwrite($stream, $bytes));
            if (!\is_int($written) || 0 === $written || null !== $warning) {
                throw new NetworkStreamException(\sprintf('Airlock cannot write to a connection: %s', $warning ?? 'it is closed'));
            }
            $bytes = substr($bytes, $written);
        }
    }

    /**
     * The streams that can be read without blocking, waiting at most the given time; none when a signal interrupted
     * the wait, so that the signal's handler runs.
     *
     * @param list<resource> $streams
     *
     * @return list<resource>
     *
     * @throws NetworkStreamException
     */
    public static function readable(array $streams, float $timeoutSeconds): array
    {
        $read = $streams;
        $write = null;
        $except = null;
        $seconds = (int) $timeoutSeconds;
        $microseconds = (int) (($timeoutSeconds - $seconds) * 1_000_000);
        [$ready, $warning] = self::call(static function () use (&$read, &$write, &$except, $seconds, $microseconds): mixed {
            return stream_select($read, $write, $except, $seconds, $microseconds);
        });
        if (false === $ready && null !== $warning && str_contains($warning, 'Interrupted system call')) {
            return [];
        }
        if (!\is_int($ready)) {
            throw new NetworkStreamException(\sprintf('Airlock cannot wait on its connections: %s', $warning ?? 'no reason given'));
        }

        // stream_select() keeps the keys of the streams it leaves out.
        $readable = [];
        foreach ($read as $stream) {
            $readable[] = $stream;
        }

        return $readable;
    }

    /**
     * @param callable(): mixed $operation
     *
     * @return array{mixed, ?string}
     */
    private static function call(callable $operation): array
    {
        $warning = null;
        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = (string) preg_replace('/^\w+\(.*?\): /', '', $message);

            return true;
        });
        try {
            $result = $operation();
        } finally {
            restore_error_handler();
        }

        return [$result, $warning];
    }
}
