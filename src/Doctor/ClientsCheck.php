<?php

declare(strict_types=1);

namespace App\Doctor;

use App\Connect\Client;
use App\Connect\Connections;
use App\Connect\Scope;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 10)]
final readonly class ClientsCheck implements Check
{
    private const string TOPIC = 'Clients';

    public function __construct(
        private Connections $connections,
    ) {
    }

    #[\Override]
    public function run(): Finding
    {
        $connected = [];
        foreach (Client::cases() as $client) {
            $scopes = $this->connections->scopesOf($client);
            if ([] !== $scopes) {
                $connected[] = \sprintf('%s (%s)', $client->label(), implode(', ', array_map(static fn (Scope $scope): string => $scope->value, $scopes)));
            }
        }

        if ([] === $connected) {
            return Finding::warning(self::TOPIC, \sprintf('Airlock is connected to none of %s from this project: run airlock connect <client>. Any other client: run airlock connect.', implode(', ', Client::values())));
        }

        return Finding::ok(self::TOPIC, \sprintf('Connected to %s, with each client\'s own shell tool off where a file can turn it off.', implode(', ', $connected)));
    }
}
