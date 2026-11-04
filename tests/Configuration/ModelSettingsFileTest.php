<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use App\Configuration\ConfigDirectory;
use App\Configuration\InvalidModelSettingsException;
use App\Configuration\ModelSettings;
use App\Configuration\ModelSettingsFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ModelSettingsFileTest extends TestCase
{
    private const string HOME = __DIR__.'/../../var/test-model-settings';

    private Filesystem $filesystem;

    private ModelSettingsFile $file;

    #[\Override]
    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->filesystem->remove(self::HOME);
        $this->file = new ModelSettingsFile(new ConfigDirectory(self::HOME));
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->filesystem->remove(self::HOME);
    }

    public function testWithoutAFileNothingIsConfigured(): void
    {
        self::assertEquals(new ModelSettings(), $this->file->read());
    }

    public function testWrittenSettingsAreReadBack(): void
    {
        $settings = new ModelSettings('https://models.example.test', 'secret-key', 'test/output-check', 'test/risk');

        $this->file->write($settings);

        self::assertEquals($settings, $this->file->read());
    }

    public function testTheFileIsReadableByItsOwnerOnly(): void
    {
        $this->file->write(new ModelSettings('https://models.example.test', 'secret-key'));

        self::assertSame(0o600, fileperms($this->file->path()) & 0o777);
    }

    public function testAirlocksDirectoryIsCreatedOpenToItsOwnerOnly(): void
    {
        $this->file->write(new ModelSettings('https://models.example.test', 'secret-key'));

        self::assertSame(0o700, fileperms(\dirname($this->file->path())) & 0o777, 'The first file Airlock writes creates its directory: it must be as closed as the audit log and the grants make it.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidFiles(): iterable
    {
        yield 'not YAML' => ["base_url: [unclosed\n"];
        yield 'not a map' => ["- a list\n"];
        yield 'a setting that is not a string' => ["base_url: [1, 2]\n"];
    }

    #[DataProvider('invalidFiles')]
    public function testAFileThatHoldsNoSettingsIsRefused(string $content): void
    {
        $this->filesystem->dumpFile($this->file->path(), $content);

        $this->expectException(InvalidModelSettingsException::class);

        $this->file->read();
    }

    public function testAKeyInAFileThatIsNotYamlIsNotRepeatedInTheError(): void
    {
        $this->filesystem->dumpFile($this->file->path(), "base_url: https://models.example.test\napi_key: sk-secret-1234: x\n");

        try {
            $this->file->read();
            self::fail('A file that is not YAML is refused.');
        } catch (InvalidModelSettingsException $e) {
            self::assertStringNotContainsString('sk-secret', $e->getMessage(), 'The message is shown by airlock doctor and logged: it must not carry the key.');
            self::assertStringContainsString('models.yaml is not valid YAML', $e->getMessage());
        }
    }
}
