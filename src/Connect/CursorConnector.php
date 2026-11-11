<?php

declare(strict_types=1);

namespace App\Connect;

/**
 * Cursor: the server in the project's `.cursor/mcp.json`. Cursor's own terminal cannot be turned off, by a file or a setting.
 */
final readonly class CursorConnector implements ClientConnector
{
    #[\Override]
    public function client(): Client
    {
        return Client::Cursor;
    }

    #[\Override]
    public function scopes(): array
    {
        return [Scope::Project];
    }

    #[\Override]
    public function edits(Scope $scope, ConnectionContext $context): array
    {
        $server = new ConfigTable([
            'command' => $context->server->command,
            'args' => $context->server->arguments,
            'env' => new ConfigTable(['AIRLOCK_PROJECT_DIR' => $context->projectDirectory]),
        ]);

        return [new FileEdit($context->projectDirectory.'/.cursor/mcp.json', ConfigFormat::Json, [new SetEntry(ConfigPath::of('mcpServers', 'airlock'), $server)])];
    }

    #[\Override]
    public function notes(Scope $scope): array
    {
        return [
            'Cursor\'s own terminal cannot be turned off: in Cursor Settings, Agents, set Run Mode to Allowlist and leave its Shell allowlist empty, so that Cursor asks you before any command that does not go through Airlock.',
            '.cursor/mcp.json holds this machine\'s paths: keep it out of version control.',
        ];
    }
}
