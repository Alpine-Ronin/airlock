<?php

declare(strict_types=1);

namespace App\Network;

final readonly class TcpDialer implements Dialer
{
    private const float TIMEOUT_SECONDS = 10.0;

    #[\Override]
    public function open(string $address, int $port)
    {
        $host = str_contains($address, ':') ? \sprintf('[%s]', $address) : $address;

        return Streams::client(\sprintf('tcp://%s:%d', $host, $port), self::TIMEOUT_SECONDS);
    }
}
