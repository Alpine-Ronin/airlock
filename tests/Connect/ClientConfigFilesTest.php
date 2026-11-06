<?php

declare(strict_types=1);

namespace App\Tests\Connect;

use App\Configuration\ConfigDirectory;
use App\Connect\AddToList;
use App\Connect\ClientConfigFiles;
use App\Connect\ConfigChangedException;
use App\Connect\ConfigFormat;
use App\Connect\ConfigPath;
use App\Connect\ConfigSyntaxes;
use App\Connect\ConnectionOriginals;
use App\Connect\Direction;
use App\Connect\FileEdit;
use App\Connect\FrontMatterConfig;
use App\Connect\JsonConfig;
use App\Connect\TomlConfig;
use App\Connect\YamlConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class ClientConfigFilesTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../var/test-client-config-files';

    private Filesystem $filesystem;

    #[\Override]
    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->filesystem->remove(self::ROOT);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->filesystem->remove(self::ROOT);
    }

    public function testAFileChangedSinceItWasPlannedStopsEveryWrite(): void
    {
        $files = $this->files();
        $this->filesystem->dumpFile(self::ROOT.'/second.json', '{}');
        $planned = [
            $files->plan($this->edit(self::ROOT.'/first.json'), Direction::Connect),
            $files->plan($this->edit(self::ROOT.'/second.json'), Direction::Connect),
        ];
        $this->filesystem->dumpFile(self::ROOT.'/second.json', '{"permissions": {"deny": ["WebFetch"]}}');

        try {
            $files->writeAll($planned, Direction::Connect);
            self::fail('Writing over a file changed since it was planned would undo that change.');
        } catch (ConfigChangedException $e) {
            self::assertStringContainsString('second.json changed since Airlock read it', $e->getMessage());
        }

        self::assertFileDoesNotExist(self::ROOT.'/first.json', 'Nothing is written when one file changed.');
        self::assertStringEqualsFile(self::ROOT.'/second.json', '{"permissions": {"deny": ["WebFetch"]}}');
    }

    public function testAFileCreatedSinceItWasPlannedStopsTheWrite(): void
    {
        $files = $this->files();
        $planned = [$files->plan($this->edit(self::ROOT.'/first.json'), Direction::Connect)];
        $this->filesystem->dumpFile(self::ROOT.'/first.json', '{}');

        $this->expectException(ConfigChangedException::class);

        $files->writeAll($planned, Direction::Connect);
    }

    public function testAFileReplacedByADirectoryStopsTheWriteAsAChange(): void
    {
        $files = $this->files();
        $this->filesystem->dumpFile(self::ROOT.'/first.json', '{}');
        $planned = [$files->plan($this->edit(self::ROOT.'/first.json'), Direction::Connect)];
        $this->filesystem->remove(self::ROOT.'/first.json');
        $this->filesystem->mkdir(self::ROOT.'/first.json');

        $this->expectException(ConfigChangedException::class);

        $files->writeAll($planned, Direction::Connect);
    }

    public function testAFileReplacedByAnotherProcessIsSeenAsChanged(): void
    {
        $files = $this->files();
        $this->filesystem->dumpFile(self::ROOT.'/first.json', '{}');
        $planned = [$files->plan($this->edit(self::ROOT.'/first.json'), Direction::Connect)];
        new Process(['sh', '-c', 'rm "$0" && mkdir "$0"', self::ROOT.'/first.json'])->mustRun();

        $this->expectException(ConfigChangedException::class);

        $files->writeAll($planned, Direction::Connect);
    }

    private function files(): ClientConfigFiles
    {
        return new ClientConfigFiles(
            new ConfigSyntaxes(new JsonConfig(), new YamlConfig(), new TomlConfig(), new FrontMatterConfig(new YamlConfig())),
            new ConnectionOriginals(new ConfigDirectory(self::ROOT.'/home')),
        );
    }

    private function edit(string $path): FileEdit
    {
        return new FileEdit($path, ConfigFormat::Json, [new AddToList(ConfigPath::of('permissions', 'deny'), 'Bash')]);
    }
}
