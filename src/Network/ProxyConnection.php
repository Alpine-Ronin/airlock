<?php

declare(strict_types=1);

namespace App\Network;

/**
 * One command's connection to the proxy: its request head is read whole, then the gate opens the host it names, and
 * from there the bytes are relayed both ways until either side closes.
 */
final class ProxyConnection
{
    private const int MOST_HEAD_BYTES = 16 * 1024;

    private const int CHUNK_BYTES = 64 * 1024;

    private string $received = '';

    /** @var resource|null */
    private $upstream;

    private bool $isClosed = false;

    /**
     * @param resource $client
     */
    public function __construct(
        private $client,
        private readonly ProxyGate $gate,
    ) {
    }

    /**
     * @return list<resource>
     */
    public function streams(): array
    {
        if (null === $this->upstream) {
            return [$this->client];
        }

        return [$this->client, $this->upstream];
    }

    public function isClosed(): bool
    {
        return $this->isClosed;
    }

    /**
     * @param resource $stream one of streams(), that can be read without blocking
     */
    public function readFrom($stream): void
    {
        try {
            $bytes = Streams::read($stream, self::CHUNK_BYTES);
        } catch (NetworkStreamException) {
            $this->close();

            return;
        }
        if ('' === $bytes) {
            $this->close();

            return;
        }
        if (null !== $this->upstream) {
            $this->send($stream === $this->client ? $this->upstream : $this->client, $bytes);

            return;
        }
        $this->received .= $bytes;
        $this->readHead();
    }

    public function close(): void
    {
        if ($this->isClosed) {
            return;
        }
        $this->isClosed = true;
        fclose($this->client);
        if (null !== $this->upstream) {
            fclose($this->upstream);
        }
    }

    private function readHead(): void
    {
        $end = strpos($this->received, "\r\n\r\n");
        if (false === $end) {
            if (\strlen($this->received) > self::MOST_HEAD_BYTES) {
                $this->answer(431, 'Request Header Fields Too Large', 'Airlock: the request\'s head is too long.');
            }

            return;
        }
        $head = substr($this->received, 0, $end + 4);
        $afterHead = substr($this->received, $end + 4);
        $this->received = '';
        try {
            $request = ProxyRequest::fromHead($head);
        } catch (MalformedProxyRequestException $e) {
            $this->answer(400, 'Bad Request', 'Airlock: '.$e->getMessage());

            return;
        }
        $this->connect($request, $afterHead);
    }

    private function connect(ProxyRequest $request, string $afterHead): void
    {
        try {
            $upstream = $this->gate->open($request->target);
        } catch (ProxyRefusedException $e) {
            $this->answer($e->decision->status(), $e->decision->reasonPhrase(), $e->getMessage());

            return;
        }
        $this->upstream = $upstream;
        if ($request->isTunnel) {
            $this->send($this->client, "HTTP/1.1 200 Connection established\r\n\r\n");
            $this->sendUpstream($afterHead);

            return;
        }
        $this->sendUpstream($request->headToForward.$afterHead);
    }

    private function sendUpstream(string $bytes): void
    {
        if ('' === $bytes || $this->isClosed || null === $this->upstream) {
            return;
        }
        $this->send($this->upstream, $bytes);
    }

    private function answer(int $status, string $reason, string $message): void
    {
        $body = $message."\n";
        $this->send($this->client, \sprintf(
            "HTTP/1.1 %d %s\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: %d\r\nConnection: close\r\n\r\n%s",
            $status,
            $reason,
            \strlen($body),
            $body,
        ));
        $this->close();
    }

    /**
     * @param resource $stream
     */
    private function send($stream, string $bytes): void
    {
        try {
            Streams::write($stream, $bytes);
        } catch (NetworkStreamException) {
            $this->close();
        }
    }
}
