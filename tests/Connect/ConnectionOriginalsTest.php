<?php

declare(strict_types=1);

namespace App\Tests\Connect;

use App\Configuration\ConfigDirectory;
use App\Connect\ConnectionOriginals;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

final class ConnectionOriginalsTest extends TestCase
{
    private const string HOME = __DIR__.'/../../var/test-connection-originals';

    private Filesystem $filesystem;

    private ConnectionOriginals $originals;

    #[\Override]
    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->filesystem->remove(self::HOME);
        $this->originals = new ConnectionOriginals(new ConfigDirectory(self::HOME));
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->filesystem->remove(self::HOME);
    }

    public function testTheFirstOriginalIsTheOneKept(): void
    {
        $this->originals->keep('/project/.mcp.json', true, '{"first": true}');
        $this->originals->keep('/project/.mcp.json', true, '{"second": true}');

        self::assertSame('{"first": true}', $this->originals->of('/project/.mcp.json'));
    }

    public function testAFileThatDidNotExistIsKeptAsEmpty(): void
    {
        $this->originals->keep('/project/.mcp.json', false, '');

        self::assertSame('', $this->originals->of('/project/.mcp.json'));
        self::assertTrue($this->originals->wasAbsent('/project/.mcp.json'));
    }

    public function testTheDirectoriesCreatedForTheFileAreKeptWithIt(): void
    {
        $this->originals->keep('/project/.claude/settings.local.json', false, '', ['/project/.claude']);

        self::assertSame(['/project/.claude'], $this->originals->createdDirectories('/project/.claude/settings.local.json'));
    }

    public function testARecordAnEarlierVersionWroteNamesNoCreatedDirectory(): void
    {
        $record = $this->originals->recordPath('/project/.mcp.json');
        $this->filesystem->dumpFile($record, '{"path":"/project/.mcp.json","existed":false,"content":""}');

        self::assertSame([], $this->originals->createdDirectories('/project/.mcp.json'));
    }

    public function testAnEmptyFileIsNotTakenForAnAbsentOne(): void
    {
        $this->originals->keep('/project/.mcp.json', true, '');

        self::assertFalse($this->originals->wasAbsent('/project/.mcp.json'));
    }

    public function testWithoutARecordAFileIsNotKnownToHaveBeenAbsent(): void
    {
        self::assertFalse($this->originals->wasAbsent('/project/.mcp.json'));
    }

    public function testTheRecordIsReadableByItsOwnerOnly(): void
    {
        $this->originals->keep('/home/you/.claude.json', true, '{"oauthAccount": {}}');

        $record = $this->originals->recordPath('/home/you/.claude.json');
        self::assertSame(0o600, fileperms($record) & 0o777);
        self::assertSame(0o700, fileperms(\dirname($record)) & 0o777);
        $entries = scandir(\dirname($record));
        self::assertIsArray($entries);
        self::assertSame([basename($record)], array_values(array_diff($entries, ['.', '..'])), 'No temporary file is left behind.');
    }

    public function testARecordLeftEmptyIsReplacedByTheOriginal(): void
    {
        $this->filesystem->dumpFile($this->originals->recordPath('/project/.mcp.json'), '');

        $this->originals->keep('/project/.mcp.json', true, '{"theme": "dark"}');

        self::assertSame('{"theme": "dark"}', $this->originals->of('/project/.mcp.json'));
    }

    public function testARecordThatCannotBeReadIsReplacedByTheOriginal(): void
    {
        $record = $this->originals->recordPath('/project/.mcp.json');
        $this->filesystem->dumpFile($record, '{"path": "/project/.mcp.json", "existed": true, "content": "stale"}');
        $this->filesystem->chmod($record, 0o000);
        if (is_readable($record)) {
            self::markTestSkipped('This user reads files whatever their mode, root for one: no record can be made unreadable to it.');
        }

        self::assertNull($this->originals->of('/project/.mcp.json'));

        $this->originals->keep('/project/.mcp.json', true, '{"theme": "dark"}');

        self::assertSame('{"theme": "dark"}', $this->originals->of('/project/.mcp.json'));
    }

    public function testARecordThatCannotBeWrittenLeavesNoTemporaryFile(): void
    {
        $record = $this->originals->recordPath('/project/.mcp.json');
        $this->filesystem->mkdir($record.'/in-the-way');

        try {
            $this->originals->keep('/project/.mcp.json', true, '{}');
            self::fail('A record cannot be renamed over a directory.');
        } catch (IOException) {
        }

        $entries = scandir(\dirname($record));
        self::assertIsArray($entries);
        self::assertSame([basename($record)], array_values(array_diff($entries, ['.', '..'])), 'The temporary record is removed when the rename fails.');
    }

    public function testADirectoryAnEarlierVersionLeftOpenIsClosed(): void
    {
        $this->originals->keep('/project/.mcp.json', true, '{}');
        $directory = \dirname($this->originals->recordPath('/project/.mcp.json'));
        $this->filesystem->chmod($directory, 0o755);

        $this->originals->keep('/project/.mcp.json', true, '{"second": true}');

        self::assertSame(0o700, fileperms($directory) & 0o777, 'Other users cannot list which client files Airlock kept.');
    }
}
