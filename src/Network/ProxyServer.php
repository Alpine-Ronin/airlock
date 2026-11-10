<?php

declare(strict_types=1);

namespace App\Network;

/**
 * The proxy listening: each tick waits for the connections that have something to read, and serves them, one process
 * and no thread, so that a command's few connections need nothing installed.
 */
final class ProxyServer
{
    /** @var array<int, ProxyConnection> */
    private array $connections = [];

    /**
     * @param resource $listener
     */
    public function __construct(
        private $listener,
        private readonly ListenAddress $address,
        private readonly ProxyGate $gate,
    ) {
    }

    /**
     * The Unix socket's file, or 127.0.0.1 and the port it got.
     */
    public function address(): string
    {
        if (null !== $this->address->socketFile) {
            return $this->address->socketFile;
        }

        return (string) stream_socket_get_name($this->listener, false);
    }

    /**
     * @throws NetworkStreamException when the connections cannot be waited on at all
     */
    public function tick(float $timeoutSeconds): void
    {
        $streams = [$this->listener];
        $owners = [];
        foreach ($this->connections as $connection) {
            foreach ($connection->streams() as $stream) {
                $streams[] = $stream;
                $owners[get_resource_id($stream)] = $connection;
            }
        }
        foreach (Streams::readable($streams, $timeoutSeconds) as $stream) {
            if ($stream === $this->listener) {
                $this->accept();

                continue;
            }
            $owner = $owners[get_resource_id($stream)];
            if (!$owner->isClosed()) {
                $owner->readFrom($stream);
            }
        }
        foreach ($this->connections as $key => $connection) {
            if ($connection->isClosed()) {
                unset($this->connections[$key]);
            }
        }
    }

    public function close(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->connections = [];
        fclose($this->listener);
        if (null !== $this->address->socketFile && file_exists($this->address->socketFile)) {
            unlink($this->address->socketFile);
        }
    }

    private function accept(): void
    {
        try {
            $client = Streams::accept($this->listener);
        } catch (NetworkStreamException) {
            // The client gave up before it was accepted: there is nothing to serve.
            return;
        }
        $connection = new ProxyConnection($client, $this->gate);
        $this->connections[spl_object_id($connection)] = $connection;
    }
}
