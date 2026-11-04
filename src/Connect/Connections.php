<?php

declare(strict_types=1);

namespace App\Connect;

use App\Execution\ProjectDirectory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Where Airlock is connected from this project, as `airlock connect <client> --check` would say it: every change
 * connecting makes, already in the client's files.
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
     * @return list<Scope> the scopes $client is connected at, none when a file cannot be read safely
     */
    public function scopesOf(Client $client): array
    {
        $connector = $this->connectors->for($client);
        $context = new ConnectionContext($this->projectDirectory->path, $this->home, $this->executable->serverCommand());
        $connected = [];
        foreach ($connector->scopes() as $scope) {
            if ($this->isConnected($connector->edits($scope, $context))) {
                $connected[] = $scope;
            }
        }

        return $connected;
    }

    /**
     * @param non-empty-list<FileEdit> $edits
     */
    private function isConnected(array $edits): bool
    {
        try {
            foreach ($edits as $edit) {
                if ($this->files->plan($edit, Direction::Connect)->isChanged()) {
                    return false;
                }
            }
        } catch (UnsupportedConfigException) {
            return false;
        }

        return true;
    }
}
