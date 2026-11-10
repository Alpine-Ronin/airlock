<?php

declare(strict_types=1);

namespace App\Network;

interface Dialer
{
    /**
     * A connection to the address, blocking.
     *
     * @return resource
     *
     * @throws NetworkStreamException
     */
    public function open(string $address, int $port);
}
