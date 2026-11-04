<?php

declare(strict_types=1);

namespace App\Connect;

final readonly class Connection
{
    public function __construct(
        public Client $client,
        public Scope $scope,
        public ConnectionState $state,
    ) {
    }
}
