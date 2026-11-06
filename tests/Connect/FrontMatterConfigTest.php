<?php

declare(strict_types=1);

namespace App\Tests\Connect;

use App\Connect\ConfigPath;
use App\Connect\FrontMatterConfig;
use App\Connect\SetEntry;
use App\Connect\UnsupportedConfigException;
use App\Connect\YamlConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class FrontMatterConfigTest extends TestCase
{
    private const string AGENT = <<<'MARKDOWN'
        ---
        model: some-model
        ---
        Review the diff, then say what you found.

        MARKDOWN;

    public function testANewFileHoldsOnlyTheFrontMatter(): void
    {
        $content = $this->syntax()->connect('', $this->changes());

        self::assertSame("---\ndescription: 'Runs commands through Airlock.'\ntools:\n  - 'airlock/*'\n  - read\n---\n", $content);
    }

    public function testTheSettingsAreSetAndTheRestOfTheFileKept(): void
    {
        $content = $this->syntax()->connect(self::AGENT, $this->changes());

        [$frontMatter, $body] = $this->parts($content);
        self::assertSame(['model' => 'some-model', 'description' => 'Runs commands through Airlock.', 'tools' => ['airlock/*', 'read']], $frontMatter);
        self::assertSame("Review the diff, then say what you found.\n", $body);
    }

    public function testAFileWithoutFrontMatterGetsOneAboveItsText(): void
    {
        $content = $this->syntax()->connect("Use Airlock.\n", $this->changes());

        self::assertSame("Use Airlock.\n", $this->parts($content)[1]);
    }

    public function testDisconnectingKeepsTheUsersSettingsAndText(): void
    {
        $connected = $this->syntax()->connect(self::AGENT, $this->changes());

        $content = $this->syntax()->disconnect($connected, $this->changes(), self::AGENT);

        self::assertSame(self::AGENT, $content);
    }

    public function testDisconnectingLeavesNoEmptyFrontMatter(): void
    {
        $connected = $this->syntax()->connect("Use Airlock.\n", $this->changes());

        $content = $this->syntax()->disconnect($connected, $this->changes(), "Use Airlock.\n");

        self::assertSame("Use Airlock.\n", $content);
    }

    public function testAFrontMatterThatIsNeverClosedIsLeftToTheUser(): void
    {
        $this->expectException(UnsupportedConfigException::class);

        $this->syntax()->connect("---\ndescription: unfinished\n", $this->changes());
    }

    private function syntax(): FrontMatterConfig
    {
        return new FrontMatterConfig(new YamlConfig());
    }

    /**
     * @return non-empty-list<SetEntry>
     */
    private function changes(): array
    {
        return [
            new SetEntry(ConfigPath::of('description'), 'Runs commands through Airlock.'),
            new SetEntry(ConfigPath::of('tools'), ['airlock/*', 'read']),
        ];
    }

    /**
     * @return array{mixed, string} the decoded front matter and the text after it
     */
    private function parts(string $content): array
    {
        self::assertSame(1, preg_match('/\A---\n(.*?)^---\n/sm', $content, $match), $content);

        return [Yaml::parse($match[1]), substr($content, \strlen($match[0]))];
    }
}
