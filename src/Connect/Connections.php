<?php

declare(strict_types=1);

namespace App\Connect;

use App\Execution\ProjectDirectory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * How each client is connected from this project, as `airlock connect <client> --check` would see it, and when it is
 * not, what is missing: Airlock's server entry, which is this Airlock's, or the rest of what connecting writes.
 */
final readonly class Connections
{
    public function __construct(
        private ClientConnectors $connectors,
        private ClientConfigFiles $files,
        private AirlockExecutable $executable,
        private ProjectDirectory $projectDirectory,
        #[Autowire(env: 'HOME')]
        private string $home,
    ) {
    }

    /**
     * @return list<Connection> one per scope the client offers
     */
    public function of(Client $client): array
    {
        $connector = $this->connectors->for($client);
        $context = new ConnectionContext($this->projectDirectory->path, $this->home, $this->executable->serverCommand());
        $connections = [];
        foreach ($connector->scopes() as $scope) {
            $connections[] = new Connection($client, $scope, $this->stateOf($connector->edits($scope, $context)));
        }

        return $connections;
    }

    /**
     * @param non-empty-list<FileEdit> $edits
     */
    private function stateOf(array $edits): ConnectionState
    {
        try {
            if (!$this->changesToConnect($edits)) {
                return ConnectionState::Connected;
            }
            $serverEntries = $this->serverEntries($edits);
            if (!$this->hasEntries($serverEntries)) {
                return ConnectionState::NotConnected;
            }

            return $this->changesToConnect($serverEntries) ? ConnectionState::OtherAirlock : ConnectionState::Incomplete;
        } catch (UnsupportedConfigException) {
            return ConnectionState::NotConnected;
        }
    }

    /**
     * @param list<FileEdit> $edits
     *
     * @throws UnsupportedConfigException
     */
    private function changesToConnect(array $edits): bool
    {
        return array_any($edits, fn (FileEdit $edit): bool => $this->files->plan($edit, Direction::Connect)->isChanged());
    }

    /**
     * Whether disconnecting would remove an entry: one is there, whatever Airlock it starts.
     *
     * @param list<FileEdit> $serverEntries
     *
     * @throws UnsupportedConfigException
     */
    private function hasEntries(array $serverEntries): bool
    {
        return [] !== $serverEntries && array_all($serverEntries, fn (FileEdit $edit): bool => $this->files->plan($edit, Direction::Disconnect)->isChanged());
    }

    /**
     * The edits reduced to Airlock's own entries, those that start its server.
     *
     * @param non-empty-list<FileEdit> $edits
     *
     * @return list<FileEdit>
     */
    private function serverEntries(array $edits): array
    {
        $serverEntries = [];
        foreach ($edits as $edit) {
            $entries = array_values(array_filter($edit->changes, static fn (ConfigChange $change): bool => $change instanceof SetEntry));
            if ([] !== $entries) {
                $serverEntries[] = new FileEdit($edit->path, $edit->format, $entries);
            }
        }

        return $serverEntries;
    }
}
