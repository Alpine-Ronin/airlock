<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Connect\Client;
use App\Connect\ClientConnectors;
use App\Tests\Output\RecordedResponses;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class ConnectCommandTest extends KernelTestCase
{
    private const string ROOT = __DIR__.'/../../var/test-connect';

    private const string HOME = self::ROOT.'/home';

    private const string PROJECT = self::ROOT.'/project';

    private Filesystem $filesystem;

    /** @var array<mixed> */
    private array $server;

    /** @var array<mixed> */
    private array $environment;

    #[\Override]
    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->filesystem->remove(self::ROOT);
        $this->filesystem->mkdir([self::HOME, self::PROJECT]);
        $this->server = $_SERVER;
        $this->environment = $_ENV;
        $_SERVER['HOME'] = $_ENV['HOME'] = self::HOME;
        $_SERVER['AIRLOCK_PROJECT_DIR'] = $_ENV['AIRLOCK_PROJECT_DIR'] = self::PROJECT;
        $_SERVER['XDG_CONFIG_HOME'] = $_ENV['XDG_CONFIG_HOME'] = self::HOME.'/.config';
        unset($_SERVER['CODEX_HOME'], $_ENV['CODEX_HOME']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_ENV = $this->environment;
        $this->filesystem->remove(self::ROOT);
        parent::tearDown();
    }

    public function testClaudeCodeGetsTheServerAndLosesItsShellTools(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['client' => 'claude-code']);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        $servers = $this->json(self::PROJECT.'/.mcp.json');
        self::assertSame('stdio', RecordedResponses::at($servers, 'mcpServers', 'airlock', 'type'));
        self::assertSame('mcp:server', RecordedResponses::at($servers, 'mcpServers', 'airlock', 'args', 1));
        self::assertSame(['deny' => ['Bash', 'Monitor'], 'allow' => ['mcp__airlock__*']], $this->json(self::PROJECT.'/.claude/settings.local.json')['permissions'] ?? null);
    }

    public function testCheckSaysWhetherAirlockIsConnected(): void
    {
        $before = $this->tester();
        self::assertSame(Command::FAILURE, $before->execute(['client' => 'claude-code', '--check' => true]));
        self::assertFileDoesNotExist(self::PROJECT.'/.mcp.json', '--check writes nothing.');

        $this->tester()->execute(['client' => 'claude-code']);

        self::assertSame(Command::SUCCESS, $this->tester()->execute(['client' => 'claude-code', '--check' => true]));
    }

    public function testRemovingTakesBackOnlyWhatConnectingAdded(): void
    {
        $this->filesystem->dumpFile(self::PROJECT.'/.claude/settings.local.json', '{"permissions": {"deny": ["WebFetch", "Bash"]}}');
        $this->tester()->execute(['client' => 'claude-code']);

        $status = $this->tester()->execute(['client' => 'claude-code', '--remove' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(['deny' => ['WebFetch', 'Bash'], 'allow' => []], $this->json(self::PROJECT.'/.claude/settings.local.json')['permissions'] ?? null, 'Bash was denied before Airlock: it stays denied.');
        self::assertFileDoesNotExist(self::PROJECT.'/.mcp.json', 'Connecting created it: removing takes it back.');
    }

    public function testRemovingDeletesTheFilesConnectingCreated(): void
    {
        $this->tester()->execute(['client' => 'claude-code']);
        $tester = $this->tester();

        $status = $tester->execute(['client' => 'claude-code', '--remove' => true]);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        self::assertFileDoesNotExist(self::PROJECT.'/.mcp.json');
        self::assertFileDoesNotExist(self::PROJECT.'/.claude/settings.local.json');
        self::assertStringContainsString('.mcp.json (removed)', $tester->getDisplay());
        self::assertDirectoryDoesNotExist(self::PROJECT.'/.claude', 'Connecting created it for settings.local.json: removing takes it back.');
        self::assertDirectoryExists(self::PROJECT);
    }

    public function testRemovingTakesBackADirectoryConnectingCreatedForAFileTheUserDeleted(): void
    {
        $this->tester()->execute(['client' => 'claude-code']);
        $this->filesystem->remove(self::PROJECT.'/.claude/settings.local.json');

        $this->tester()->execute(['client' => 'claude-code', '--remove' => true]);

        self::assertDirectoryDoesNotExist(self::PROJECT.'/.claude', 'Removing forgets the record: it is the last chance to take the directory back.');
    }

    public function testRemovingKeepsADirectoryThatExistedBefore(): void
    {
        $this->filesystem->mkdir(self::PROJECT.'/.claude');
        $this->tester()->execute(['client' => 'claude-code']);

        $this->tester()->execute(['client' => 'claude-code', '--remove' => true]);

        self::assertDirectoryExists(self::PROJECT.'/.claude', 'The user had it, empty as it was.');
    }

    public function testRemovingKeepsADirectoryConnectingCreatedOnceTheUserAddedToIt(): void
    {
        $this->tester()->execute(['client' => 'claude-code']);
        $this->filesystem->dumpFile(self::PROJECT.'/.claude/commands/review.md', 'Review the diff.');

        $this->tester()->execute(['client' => 'claude-code', '--remove' => true]);

        self::assertFileExists(self::PROJECT.'/.claude/commands/review.md');
    }

    public function testRemovingTakesBackEveryDirectoryConnectingCreatedOnTheWay(): void
    {
        $_SERVER['CODEX_HOME'] = $_ENV['CODEX_HOME'] = self::ROOT.'/codex/home';
        $this->tester()->execute(['client' => 'codex']);

        $this->tester()->execute(['client' => 'codex', '--remove' => true]);

        self::assertDirectoryDoesNotExist(self::ROOT.'/codex', 'Both codex/ and codex/home/ were created by connecting.');
        self::assertDirectoryExists(self::ROOT);
    }

    public function testADirectoryAlreadyGoneDoesNotStopTheOnesAboveIt(): void
    {
        $_SERVER['CODEX_HOME'] = $_ENV['CODEX_HOME'] = self::ROOT.'/codex/home';
        $this->tester()->execute(['client' => 'codex']);
        $this->filesystem->remove(self::ROOT.'/codex/home');

        $this->tester()->execute(['client' => 'codex', '--remove' => true]);

        self::assertDirectoryDoesNotExist(self::ROOT.'/codex', 'codex/home/ is gone, but codex/ was created by connecting too.');
    }

    public function testRemovingKeepsAFileConnectingCreatedOnceTheUserAddedToIt(): void
    {
        $this->tester()->execute(['client' => 'claude-code']);
        $servers = ['mcpServers' => [
            'airlock' => RecordedResponses::at($this->json(self::PROJECT.'/.mcp.json'), 'mcpServers', 'airlock'),
            'other' => ['type' => 'stdio', 'command' => 'other'],
        ]];
        $this->filesystem->dumpFile(self::PROJECT.'/.mcp.json', json_encode($servers, \JSON_THROW_ON_ERROR));

        $this->tester()->execute(['client' => 'claude-code', '--remove' => true]);

        self::assertSame(['mcpServers' => ['other' => ['type' => 'stdio', 'command' => 'other']]], $this->json(self::PROJECT.'/.mcp.json'));
    }

    public function testRemovingKeepsAnEmptyFileThatExistedBefore(): void
    {
        $this->filesystem->dumpFile(self::PROJECT.'/.mcp.json', '');
        $this->tester()->execute(['client' => 'claude-code']);

        $this->tester()->execute(['client' => 'claude-code', '--remove' => true]);

        self::assertFileExists(self::PROJECT.'/.mcp.json', 'The user had this file, empty as it was.');
    }

    public function testWithoutARecordOfTheOriginalSharedSettingsAreLeftAndSaidSo(): void
    {
        $this->tester()->execute(['client' => 'claude-code']);
        $this->filesystem->remove(self::HOME.'/.config/airlock/originals');
        $tester = $this->tester();

        $tester->execute(['client' => 'claude-code', '--remove' => true]);

        self::assertSame(['deny' => ['Bash', 'Monitor'], 'allow' => ['mcp__airlock__*']], $this->json(self::PROJECT.'/.claude/settings.local.json')['permissions'] ?? null);
        self::assertSame(['mcpServers' => []], $this->json(self::PROJECT.'/.mcp.json'), 'Airlock\'s own server entry goes either way.');
        self::assertStringContainsString('has no record of', $tester->getDisplay());
    }

    public function testADryRunWritesNothing(): void
    {
        $tester = $this->tester();

        $tester->execute(['client' => 'cursor', '--dry-run' => true]);

        self::assertFileDoesNotExist(self::PROJECT.'/.cursor/mcp.json');
        self::assertStringContainsString('sets mcpServers.airlock', $tester->getDisplay());
    }

    public function testTheFileAsItWasIsKeptOutOfReachAndReadableByItsOwnerOnly(): void
    {
        $original = '{"oauthAccount": {"emailAddress": "you@example.test"}}';
        $this->filesystem->dumpFile(self::HOME.'/.claude.json', $original);
        $this->filesystem->chmod(self::HOME.'/.claude.json', 0o600);

        $this->tester()->execute(['client' => 'claude-code', '--scope' => 'user']);

        $records = glob(self::HOME.'/.config/airlock/originals/.claude.json-*.json');
        self::assertIsArray($records);
        self::assertCount(1, $records);
        self::assertSame(0o600, fileperms($records[0]) & 0o777, 'The original of a file holding credentials is readable by its owner only.');
        self::assertSame($original, RecordedResponses::at(json_decode((string) file_get_contents($records[0]), true), 'content'));
        self::assertSame(0o600, fileperms(self::HOME.'/.claude.json') & 0o777);
        self::assertNotNull(RecordedResponses::at($this->json(self::HOME.'/.claude.json'), 'mcpServers', 'airlock'));
    }

    public function testOnlyAFileAirlockChangesIsKept(): void
    {
        $this->tester()->execute(['client' => 'claude-code']);
        $this->filesystem->remove(self::HOME.'/.config/airlock/originals');
        $this->filesystem->dumpFile(self::PROJECT.'/.claude/settings.local.json', '{"permissions": {"deny": ["Bash"]}}');

        $this->tester()->execute(['client' => 'claude-code']);

        self::assertSame([], glob(self::HOME.'/.config/airlock/originals/.mcp.json-*.json'), '.mcp.json already held Airlock\'s server: there is no original of it to keep.');
        $kept = glob(self::HOME.'/.config/airlock/originals/settings.local.json-*.json');
        self::assertIsArray($kept);
        self::assertCount(1, $kept);
    }

    public function testRemovingDoesNotBringBackAFileTheUserDeleted(): void
    {
        $_SERVER['CODEX_HOME'] = $_ENV['CODEX_HOME'] = self::ROOT.'/codex';
        $this->filesystem->dumpFile(self::ROOT.'/codex/config.toml', "[features]\nshell_tool = false\n");
        $this->tester()->execute(['client' => 'codex']);
        $this->filesystem->remove(self::ROOT.'/codex/config.toml');

        $this->tester()->execute(['client' => 'codex', '--remove' => true]);

        self::assertFileDoesNotExist(self::ROOT.'/codex/config.toml');
    }

    public function testARemovalWithNothingToChangeStillEndsTheConnection(): void
    {
        $this->tester()->execute(['client' => 'claude-code']);
        $this->filesystem->remove([self::PROJECT.'/.mcp.json', self::PROJECT.'/.claude/settings.local.json']);

        $status = $this->tester()->execute(['client' => 'claude-code', '--remove' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([], $this->originals(), 'A later connection starts from the files as they are then, not from these originals.');
    }

    public function testADryRunRemovalKeepsTheOriginals(): void
    {
        $this->tester()->execute(['client' => 'claude-code']);
        $this->filesystem->remove([self::PROJECT.'/.mcp.json', self::PROJECT.'/.claude/settings.local.json']);
        $originals = $this->originals();

        $this->tester()->execute(['client' => 'claude-code', '--remove' => true, '--dry-run' => true]);

        self::assertCount(2, $originals, 'Connecting kept the original of .mcp.json and of settings.local.json.');
        self::assertSame($originals, $this->originals(), 'A dry run writes nothing, records included.');
    }

    public function testCodexIsConfiguredWhereCodexHomeSays(): void
    {
        $_SERVER['CODEX_HOME'] = $_ENV['CODEX_HOME'] = self::ROOT.'/codex';

        $this->tester()->execute(['client' => 'codex']);

        $config = (string) file_get_contents(self::ROOT.'/codex/config.toml');
        self::assertStringContainsString("[mcp_servers.airlock]\n", $config);
        self::assertStringContainsString("[features]\nshell_tool = false\n", $config);
    }

    public function testGooseKeepsItsDeveloperExtensionToTheFileTools(): void
    {
        $this->filesystem->dumpFile(self::HOME.'/.config/goose/config.yaml', "extensions:\n  developer:\n    enabled: true\n    name: developer\n    type: builtin\n");

        $this->tester()->execute(['client' => 'goose']);

        $config = Yaml::parseFile(self::HOME.'/.config/goose/config.yaml');
        self::assertSame(['enabled' => true, 'name' => 'developer', 'type' => 'builtin', 'available_tools' => ['write', 'edit', 'tree']], RecordedResponses::at($config, 'extensions', 'developer'));
        self::assertSame('stdio', RecordedResponses::at($config, 'extensions', 'airlock', 'type'));
    }

    public function testAGooseNotStartedYetGetsItsDeveloperExtensionWithoutTheShell(): void
    {
        $this->tester()->execute(['client' => 'goose']);

        $config = Yaml::parseFile(self::HOME.'/.config/goose/config.yaml');
        self::assertSame(['write', 'edit', 'tree'], RecordedResponses::at($config, 'extensions', 'developer', 'available_tools'), 'Goose adds its developer extension, shell included, when its configuration has none.');
        self::assertSame('platform', RecordedResponses::at($config, 'extensions', 'developer', 'type'));
        self::assertTrue(RecordedResponses::at($config, 'extensions', 'developer', 'enabled'));
    }

    public function testRemovingFromAGooseNotStartedYetDeletesItsConfiguration(): void
    {
        $this->tester()->execute(['client' => 'goose']);

        $this->tester()->execute(['client' => 'goose', '--remove' => true]);

        self::assertDirectoryDoesNotExist(self::HOME.'/.config/goose');
    }

    public function testVsCodeGetsTheServerAndAnAgentWithoutATerminal(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['client' => 'vscode']);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        self::assertSame('stdio', RecordedResponses::at($this->json(self::PROJECT.'/.vscode/mcp.json'), 'servers', 'airlock', 'type'));
        $agent = (string) file_get_contents(self::PROJECT.'/.github/agents/airlock.agent.md');
        self::assertSame(1, preg_match('/\A---\n(.*?)^---\n\z/sm', $agent, $frontMatter), $agent);
        self::assertSame(['airlock/*', 'read', 'edit', 'search'], RecordedResponses::at(Yaml::parse($frontMatter[1]), 'tools'), 'The agent has no terminal: VS Code names it execute.');
    }

    public function testRemovingFromVsCodeDeletesTheAgentAndItsDirectories(): void
    {
        $this->tester()->execute(['client' => 'vscode']);

        $this->tester()->execute(['client' => 'vscode', '--remove' => true]);

        self::assertDirectoryDoesNotExist(self::PROJECT.'/.github');
        self::assertDirectoryDoesNotExist(self::PROJECT.'/.vscode');
    }

    public function testAFileAirlockCannotEditSafelyIsLeftAsItWas(): void
    {
        $original = "{\n  // my servers\n  \"servers\": {}\n}\n";
        $this->filesystem->dumpFile(self::PROJECT.'/.vscode/mcp.json', $original);
        $tester = $this->tester();

        $status = $tester->execute(['client' => 'vscode']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringEqualsFile(self::PROJECT.'/.vscode/mcp.json', $original);
        self::assertFileDoesNotExist(self::PROJECT.'/.github/agents/airlock.agent.md', 'One file Airlock cannot edit stops them all.');
        self::assertStringContainsString('Make the change by hand', $tester->getDisplay());
    }

    public function testAClientAirlockDoesNotKnowIsRefused(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['client' => 'another-editor']));
        self::assertStringContainsString('does not know the client "another-editor"', $tester->getDisplay());
    }

    public function testAScopeTheClientDoesNotOfferIsRefused(): void
    {
        self::assertSame(Command::FAILURE, $this->tester()->execute(['client' => 'codex', '--scope' => 'project']));
    }

    public function testWithoutAClientTheEntryForAnyOtherIsShown(): void
    {
        $tester = $this->tester();

        $tester->execute([]);

        self::assertStringContainsString('"mcp:server"', $tester->getDisplay());
        self::assertStringContainsString('needs MCP elicitation', $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{Client}>
     */
    public static function clients(): iterable
    {
        foreach (Client::cases() as $client) {
            yield $client->value => [$client];
        }
    }

    #[DataProvider('clients')]
    public function testEveryClientHasAConnector(Client $client): void
    {
        $connectors = self::getContainer()->get(ClientConnectors::class);

        self::assertSame($client, $connectors->for($client)->client());
    }

    /**
     * Every original record, hidden ones included, by name.
     *
     * @return array<string, string>
     */
    private function originals(): array
    {
        $directory = self::HOME.'/.config/airlock/originals';
        if (!is_dir($directory)) {
            return [];
        }
        $records = [];
        foreach (new \FilesystemIterator($directory) as $record) {
            if ($record instanceof \SplFileInfo) {
                $records[$record->getFilename()] = (string) file_get_contents($record->getPathname());
            }
        }
        ksort($records);

        return $records;
    }

    private function tester(): CommandTester
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('connect'));
        $tester->setInputs(['yes']);

        return $tester;
    }

    /**
     * @return array<mixed>
     */
    private function json(string $path): array
    {
        $content = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($content);

        return $content;
    }
}
