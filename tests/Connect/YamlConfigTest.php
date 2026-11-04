<?php

declare(strict_types=1);

namespace App\Tests\Connect;

use App\Connect\ConfigPath;
use App\Connect\ConfigTable;
use App\Connect\SetEntry;
use App\Connect\SetInClientEntry;
use App\Connect\UnsupportedConfigException;
use App\Connect\YamlConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class YamlConfigTest extends TestCase
{
    private const string WITH_DEVELOPER = <<<'YAML'
        GOOSE_MODE: approve
        extensions:
          developer:
            bundled: true
            enabled: true
            name: developer
            type: builtin
        YAML;

    public function testTheExtensionIsAddedAndTheExistingOneRestricted(): void
    {
        $content = new YamlConfig()->connect(self::WITH_DEVELOPER, $this->changes());

        self::assertSame([
            'GOOSE_MODE' => 'approve',
            'extensions' => [
                'developer' => ['bundled' => true, 'enabled' => true, 'name' => 'developer', 'type' => 'builtin', 'available_tools' => ['write', 'edit']],
                'airlock' => ['name' => 'airlock', 'type' => 'stdio', 'cmd' => '/bin/airlock', 'args' => ['mcp:server']],
            ],
        ], Yaml::parse($content));
    }

    public function testADeveloperExtensionTheUserDoesNotHaveIsWrittenWithoutItsShell(): void
    {
        $content = new YamlConfig()->connect("GOOSE_MODE: approve\n", $this->changes());

        self::assertSame([
            'GOOSE_MODE' => 'approve',
            'extensions' => [
                'airlock' => ['name' => 'airlock', 'type' => 'stdio', 'cmd' => '/bin/airlock', 'args' => ['mcp:server']],
                'developer' => ['name' => 'developer', 'type' => 'platform', 'available_tools' => ['write', 'edit']],
            ],
        ], Yaml::parse($content));
    }

    public function testDisconnectingRemovesTheDeveloperExtensionAirlockWrote(): void
    {
        $original = "GOOSE_MODE: approve\n";
        $connected = new YamlConfig()->connect($original, $this->changes());

        $content = new YamlConfig()->disconnect($connected, $this->changes(), $original);

        self::assertSame(['GOOSE_MODE' => 'approve', 'extensions' => []], Yaml::parse($content));
    }

    public function testDisconnectingRemovesTheExtensionAndTheRestriction(): void
    {
        $connected = new YamlConfig()->connect(self::WITH_DEVELOPER, $this->changes());

        $content = new YamlConfig()->disconnect($connected, $this->changes(), self::WITH_DEVELOPER);

        self::assertSame(Yaml::parse(self::WITH_DEVELOPER), Yaml::parse($content));
    }

    public function testToolsTheUserHadChosenComeBack(): void
    {
        $original = self::WITH_DEVELOPER."\n    available_tools: [shell, tree]\n";
        $connected = new YamlConfig()->connect($original, $this->changes());

        $content = new YamlConfig()->disconnect($connected, $this->changes(), $original);

        self::assertSame(Yaml::parse($original), Yaml::parse($content));
    }

    public function testConnectingTwiceChangesNothingTheSecondTime(): void
    {
        $once = new YamlConfig()->connect(self::WITH_DEVELOPER, $this->changes());

        self::assertSame($once, new YamlConfig()->connect($once, $this->changes()));
    }

    public function testInvalidYamlIsRefused(): void
    {
        $this->expectException(UnsupportedConfigException::class);

        new YamlConfig()->connect("extensions: [\n", $this->changes());
    }

    /**
     * @return non-empty-list<SetEntry|SetInClientEntry>
     */
    private function changes(): array
    {
        return [
            new SetEntry(ConfigPath::of('extensions', 'airlock'), new ConfigTable(['name' => 'airlock', 'type' => 'stdio', 'cmd' => '/bin/airlock', 'args' => ['mcp:server']])),
            new SetInClientEntry(ConfigPath::of('extensions', 'developer', 'available_tools'), ['write', 'edit'], new ConfigTable(['name' => 'developer', 'type' => 'platform'])),
        ];
    }
}
