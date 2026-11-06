<?php

declare(strict_types=1);

namespace App\Connect;

/**
 * VS Code: the server in the project's `.vscode/mcp.json`, run in the workspace folder, and an `airlock` agent whose
 * tools leave out the terminal. No file turns the terminal off in VS Code's other agents.
 */
final readonly class VsCodeConnector implements ClientConnector
{
    #[\Override]
    public function client(): Client
    {
        return Client::VsCode;
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
            'type' => 'stdio',
            'command' => $context->server->command,
            'args' => $context->server->arguments,
            'env' => new ConfigTable(['AIRLOCK_PROJECT_DIR' => '${workspaceFolder}']),
        ]);

        return [
            new FileEdit($context->projectDirectory.'/.vscode/mcp.json', ConfigFormat::Json, [new SetEntry(ConfigPath::of('servers', 'airlock'), $server)]),
            new FileEdit($context->projectDirectory.'/.github/agents/airlock.agent.md', ConfigFormat::FrontMatter, [
                new SetEntry(ConfigPath::of('description'), 'Runs commands only through Airlock, which asks you before each one and keeps a record.'),
                new SetEntry(ConfigPath::of('tools'), ['airlock/*', 'read', 'edit', 'search']),
            ]),
        ];
    }

    #[\Override]
    public function notes(Scope $scope): array
    {
        return [
            'Pick the airlock agent in VS Code\'s chat: it has no terminal. VS Code\'s other agents keep theirs: turn it off in the chat\'s tool settings, or the agent can run commands without Airlock.',
            '.vscode/mcp.json holds this machine\'s path to Airlock: keep it out of version control.',
        ];
    }
}
