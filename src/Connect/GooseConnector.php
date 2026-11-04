<?php

declare(strict_types=1);

namespace App\Connect;

/**
 * Goose: Airlock as a stdio extension in `~/.config/goose/config.yaml`, and the developer extension kept to its file
 * tools so that its shell tool is gone. Goose adds that extension, shell included, to a configuration that has none,
 * such as before its first start: Airlock then writes it as Goose would.
 */
final readonly class GooseConnector implements ClientConnector
{
    /** The developer extension's tools, its shell tool left out. */
    private const array DEVELOPER_TOOLS = ['write', 'edit', 'tree'];

    /** The developer extension as Goose 1.57 writes it by default, its description left out: it names the shell. */
    private const array DEVELOPER_BY_DEFAULT = ['enabled' => true, 'type' => 'platform', 'name' => 'developer', 'display_name' => 'Developer', 'bundled' => true];

    #[\Override]
    public function client(): Client
    {
        return Client::Goose;
    }

    #[\Override]
    public function scopes(): array
    {
        return [Scope::User];
    }

    #[\Override]
    public function edits(Scope $scope, ConnectionContext $context): array
    {
        $extension = new ConfigTable([
            'name' => 'airlock',
            'type' => 'stdio',
            'enabled' => true,
            'cmd' => $context->server->command,
            'args' => $context->server->arguments,
            'timeout' => 300,
        ]);

        return [new FileEdit($context->home.'/.config/goose/config.yaml', ConfigFormat::Yaml, [
            new SetEntry(ConfigPath::of('extensions', 'airlock'), $extension),
            new SetInClientEntry(ConfigPath::of('extensions', 'developer', 'available_tools'), self::DEVELOPER_TOOLS, new ConfigTable(self::DEVELOPER_BY_DEFAULT)),
        ])];
    }

    #[\Override]
    public function notes(Scope $scope): array
    {
        return ['Airlock writes config.yaml back without its comments; the file as it was before Airlock first changed it is kept in Airlock\'s directory, under originals/.'];
    }
}
