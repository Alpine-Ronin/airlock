<?php

declare(strict_types=1);

namespace App\Tests\Network;

use App\Network\Dialer;
use App\Network\NetworkStreamException;
use App\Network\Streams;

/**
 * Connects every address to a server of the test's own, so that no test reaches the network, and remembers what it
 * was asked to dial. An address listed as down fails as an unreachable one does.
 */
final class LocalDialer implements Dialer
{
    /** @var list<string> */
    public array $dialed = [];

    /**
     * @param list<string> $downAddresses
     */
    public function __construct(
        private readonly string $serverAddress,
        private readonly array $downAddresses = [],
    ) {
    }

    #[\Override]
    public function open(string $address, int $port)
    {
        $this->dialed[] = \sprintf('%s:%d', $address, $port);
        if (\in_array($address, $this->downAddresses, true)) {
            throw new NetworkStreamException(\sprintf('%s is down.', $address));
        }

        return Streams::client($this->serverAddress, 1.0);
    }
}
