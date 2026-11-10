<?php

declare(strict_types=1);

namespace App\Network;

interface Resolver
{
    /**
     * The addresses a host name or address stands for; none when it has none.
     *
     * @return list<string>
     */
    public function addressesOf(string $host): array;
}
