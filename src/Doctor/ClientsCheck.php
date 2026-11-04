<?php

declare(strict_types=1);

namespace App\Doctor;

use App\Connect\Client;
use App\Connect\Connection;
use App\Connect\Connections;
use App\Connect\ConnectionState;
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
        $byState = [];
        foreach (Client::cases() as $client) {
            foreach ($this->connections->of($client) as $connection) {
                $byState[$connection->state->name][] = $connection;
            }
        }
        $connected = $byState[ConnectionState::Connected->name] ?? [];
        $others = $byState[ConnectionState::OtherAirlock->name] ?? [];
        $incomplete = $byState[ConnectionState::Incomplete->name] ?? [];

        $sentences = [];
        if ([] !== $connected) {
            $sentences[] = \sprintf('Connected to %s, with each client\'s own shell tool off where a file can turn it off.', $this->names($connected));
        }
        if ([] !== $incomplete) {
            $sentences[] = \sprintf('%s %s Airlock, but not all that connecting writes, such as its own shell tool off: the agent may run commands around Airlock. Run airlock connect <client> to finish it.', $this->names($incomplete), 1 === \count($incomplete) ? 'starts' : 'start');
        }
        if ([] !== $others) {
            $sentences[] = \sprintf('%s %s another Airlock than this one, such as an earlier install: run airlock connect <client> with the one you mean to use.', $this->names($others), 1 === \count($others) ? 'starts' : 'start');
        }

        if ([] !== $incomplete) {
            return Finding::problem(self::TOPIC, implode(' ', $sentences));
        }
        if ([] !== $others) {
            return Finding::warning(self::TOPIC, implode(' ', $sentences));
        }
        if ([] === $connected) {
            return Finding::warning(self::TOPIC, \sprintf('Airlock is connected to none of %s from this project: run airlock connect <client>. Any other client: run airlock connect.', implode(', ', Client::values())));
        }

        return Finding::ok(self::TOPIC, implode(' ', $sentences));
    }

    /**
     * @param non-empty-list<Connection> $connections
     */
    private function names(array $connections): string
    {
        return implode(', ', array_map(static fn (Connection $connection): string => \sprintf('%s (%s)', $connection->client->label(), $connection->scope->value), $connections));
    }
}
