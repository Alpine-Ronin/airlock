<?php

declare(strict_types=1);

namespace App\Network;

/**
 * Where the proxy listens: a Unix socket, which bubblewrap binds into the sandbox, or a port on 127.0.0.1, which
 * Seatbelt can open to it. Never another interface: the proxy serves this machine's commands only.
 */
final readonly class ListenAddress
{
    private function __construct(
        public string $option,
        public string $streamAddress,
        public ?string $socketFile,
    ) {
    }

    /**
     * `unix:///absolute/path`, or `127.0.0.1:<port>`, port 0 for any free one.
     *
     * @throws \InvalidArgumentException
     */
    public static function fromOption(string $address): self
    {
        if (1 === preg_match('#^unix://(?<file>/.+)$#', $address, $unix)) {
            return new self($address, $address, $unix['file']);
        }
        if (1 === preg_match('/^127\.0\.0\.1:(?<port>[0-9]{1,5})$/', $address, $local) && (int) $local['port'] <= 65535) {
            return new self($address, 'tcp://'.$address, null);
        }

        throw new \InvalidArgumentException(\sprintf('"%s" is neither unix:///absolute/path nor 127.0.0.1:<port>.', $address));
    }
}
