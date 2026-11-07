<?php

declare(strict_types=1);

namespace App\Tests\Grant;

use App\Configuration\ConfigDirectory;
use App\Execution\ProjectDirectory;
use App\Grant\Grant;
use App\Grant\InvalidGrantsException;
use App\Grant\ProjectGrants;
use App\Grant\Source;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class ProjectGrantsTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../var/test-grants';

    private Filesystem $filesystem;

    private ConfigDirectory $configDirectory;

    private ProjectDirectory $project;

    #[\Override]
    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->filesystem->remove(self::ROOT);
        $this->filesystem->mkdir([self::ROOT.'/home', self::ROOT.'/project']);
        $this->configDirectory = new ConfigDirectory((string) realpath(self::ROOT.'/home'));
        $this->project = new ProjectDirectory(self::ROOT.'/project');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->filesystem->remove(self::ROOT);
    }

    public function testAProjectWithoutGrantsHasNone(): void
    {
        self::assertSame([], $this->grants()->all());
    }

    public function testGrantsAddedAreReadBack(): void
    {
        $this->grants()->add([new Grant(['make', 'test'], coversLongerCommands: true), new Grant(['ls', '-la'], coversLongerCommands: false)]);

        self::assertEquals([new Grant(['make', 'test'], true), new Grant(['ls', '-la'], false)], $this->grants()->all());
    }

    public function testTheSourcesOfAGrantAreReadBack(): void
    {
        $grant = new Grant(['make', 'test'], coversLongerCommands: true, sources: [new Source('Makefile', hash('sha256', 'test:'))]);

        $this->grants()->add([$grant]);

        self::assertEquals([$grant], $this->grants()->all());
    }

    public function testTheGrantsFileIsWritableAndReadableByItsOwnerOnly(): void
    {
        $this->grants()->add([new Grant(['ls'], false)]);

        $file = $this->configDirectory->forProject($this->project->path).'/grants.yaml';
        self::assertSame(0o600, fileperms($file) & 0o777, 'Whoever can change it can let any command run without asking.');
        self::assertSame(0o700, fileperms(\dirname($file)) & 0o777);
        self::assertSame(0o700, fileperms($this->configDirectory->path) & 0o777);
    }

    public function testAGrantsFileAnEarlierVersionLeftOpenIsReplacedByAPrivateOne(): void
    {
        $this->grants()->add([new Grant(['ls'], false)]);
        $file = $this->configDirectory->forProject($this->project->path).'/grants.yaml';
        $this->filesystem->chmod([$file, \dirname($file)], 0o777);
        $openInode = fileinode($file);

        $this->grants()->add([new Grant(['pwd'], false)]);

        clearstatcache();
        self::assertSame(0o600, fileperms($file) & 0o777);
        self::assertSame(0o700, fileperms(\dirname($file)) & 0o777);
        self::assertNotSame($openInode, fileinode($file), 'A file opened while it was open to others must not see the new grants.');
        self::assertCount(2, $this->grants()->all());
    }

    public function testAGrantGivenTwiceIsKeptOnce(): void
    {
        $this->grants()->add([new Grant(['make', 'test'], true)]);
        $this->grants()->add([new Grant(['make', 'test'], true)]);

        self::assertCount(1, $this->grants()->all());
    }

    public function testARevokedGrantIsGoneAndTheOthersStay(): void
    {
        $this->grants()->add([new Grant(['make', 'test'], true), new Grant(['ls', '-la'], false)]);

        $this->grants()->revoke([new Grant(['make', 'test'], true)]);

        self::assertEquals([new Grant(['ls', '-la'], false)], $this->grants()->all());
    }

    public function testRevokingTheLastGrantLeavesNone(): void
    {
        $this->grants()->add([new Grant(['ls'], false)]);

        $this->grants()->revoke([new Grant(['ls'], false)]);

        self::assertSame([], $this->grants()->all());
    }

    public function testRevokingAGrantNotThereChangesNothing(): void
    {
        $this->grants()->add([new Grant(['ls'], false)]);

        $this->grants()->revoke([new Grant(['ls'], true)]);

        self::assertEquals([new Grant(['ls'], false)], $this->grants()->all(), 'A grant is revoked as it was given: `ls …` is not `ls`.');
    }

    public function testRevokingKeepsTheFilePrivate(): void
    {
        $this->grants()->add([new Grant(['ls'], false), new Grant(['pwd'], false)]);

        $this->grants()->revoke([new Grant(['ls'], false)]);

        self::assertSame(0o600, fileperms($this->configDirectory->forProject($this->project->path).'/grants.yaml') & 0o777);
    }

    public function testGrantsAreKeptPerProject(): void
    {
        $this->grants()->add([new Grant(['make', 'test'], true)]);
        $this->filesystem->mkdir(self::ROOT.'/other');

        $other = new ProjectGrants($this->configDirectory, new ProjectDirectory(self::ROOT.'/other'), new LockFactory(new InMemoryStore()));

        self::assertSame([], $other->all());
    }

    #[DataProvider('invalidFiles')]
    public function testAFileThatCannotBeReadIsNeverGuessedAt(string $content): void
    {
        $this->filesystem->dumpFile($this->configDirectory->forProject($this->project->path).'/grants.yaml', $content);

        $this->expectException(InvalidGrantsException::class);

        $this->grants()->all();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidFiles(): iterable
    {
        yield 'not YAML' => ["grants: [\n"];
        yield 'no grants list' => ["rm: -rf\n"];
        yield 'a command that is not a list of words' => ["grants:\n  - {command: 'rm -rf ~', prefix: false}\n"];
        yield 'an empty command' => ["grants:\n  - {command: [], prefix: true}\n"];
        yield 'a word that is not a string' => ["grants:\n  - {command: [rm, [x]], prefix: true}\n"];
        yield 'no prefix flag' => ["grants:\n  - {command: [ls]}\n"];
    }

    private function grants(): ProjectGrants
    {
        return new ProjectGrants($this->configDirectory, $this->project, new LockFactory(new InMemoryStore()));
    }
}
