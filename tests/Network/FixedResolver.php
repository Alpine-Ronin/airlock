<?php

declare(strict_types=1);

namespace App\Tests\Network;

use App\Network\Resolver;

final readonly class FixedResolver implements Resolver
{
    /**
     * @param array<string, list<string>> $addressesByHost
     */
    public function __construct(
        private array $addressesByHost,
    ) {
    }

    #[\Override]
    public function addressesOf(string $host): array
    {
        return $this->addressesByHost[$host] ?? [];
    }
}
