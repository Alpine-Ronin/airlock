<?php

declare(strict_types=1);

namespace App\Tests\Audit;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Audit\Outcome;
use App\Configuration\ConfigDirectory;
use App\Policy\Verdict;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class AuditLogTest extends TestCase
{
    private const string HOME = __DIR__.'/../../var/test-audit';

    private const string LOG = self::HOME.'/.config/airlock/audit.jsonl';

    private AuditLog $log;

    #[\Override]
    protected function setUp(): void
    {
        new Filesystem()->remove(self::HOME);
        $this->log = new AuditLog(new ConfigDirectory(self::HOME), new LockFactory(new InMemoryStore()));
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove(self::HOME);
    }

    public function testAnEmptyLogHasNoEntries(): void
    {
        self::assertSame([], $this->log->lastEntriesFor('/project', 10));
    }

    public function testTheLogIsReadableByItsOwnerOnly(): void
    {
        $this->log->append($this->entry('/project', 'ls'));

        self::assertSame(0o600, fileperms(self::LOG) & 0o777, 'The log holds every command run in every project.');
    }

    public function testItsDirectoryIsClosedToOthersBeforeTheLogIsMade(): void
    {
        new Filesystem()->mkdir(\dirname(self::LOG), 0o755);
        new Filesystem()->chmod(\dirname(self::LOG), 0o755);

        $this->log->append($this->entry('/project', 'ls'));

        self::assertSame(0o700, fileperms(\dirname(self::LOG)) & 0o777, 'Nobody else can open the log, even for the moment it is being made.');
    }

    public function testALogAnEarlierVersionLeftOpenIsReplacedByAPrivateCopy(): void
    {
        $earlier = $this->entry('/project', 'earlier');
        new Filesystem()->dumpFile(self::LOG, json_encode($earlier->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)."\n");
        new Filesystem()->chmod(self::LOG, 0o644);
        $openInode = fileinode(self::LOG);

        $this->log->append($this->entry('/project', 'later'));

        clearstatcache();
        self::assertSame(0o600, fileperms(self::LOG) & 0o777);
        self::assertNotSame($openInode, fileinode(self::LOG), 'A file opened while the log was readable by others must not see the later entries.');
        self::assertCount(2, $this->log->lastEntriesFor('/project', 10), 'The earlier entries are kept.');
    }

    public function testAnEntryIsWrittenAsOneJsonLine(): void
    {
        $this->log->append(new AuditEntry(new \DateTimeImmutable('2026-11-05T10:00:00+00:00'), '/project', 'rm -rf build', Verdict::AskEveryTime, Outcome::RefusedByUser, ['`rm -rf build`: it deletes recursively']));

        self::assertSame(
            ['{"at":"2026-11-05T10:00:00+00:00","project":"/project","command":"rm -rf build","verdict":"ask_every_time","outcome":"refused_by_user","reasons":["`rm -rf build`: it deletes recursively"],"exit_code":null,"timed_out":false,"detail":null,"output_check":null,"output_check_reason":null,"masked_secrets":null}'],
            $this->log->lastEntriesFor('/project', 10),
        );
    }

    public function testOnlyTheEntriesOfTheProjectAreReadBackTheLastOnesOldestFirst(): void
    {
        foreach (['a', 'b', 'c'] as $command) {
            $this->log->append($this->entry('/project', $command));
            $this->log->append($this->entry('/other', $command));
        }

        $commands = array_map(static function (string $line): mixed {
            $entry = json_decode($line, true);

            return \is_array($entry) ? $entry['command'] ?? null : null;
        }, $this->log->lastEntriesFor('/project', 2));

        self::assertSame(['b', 'c'], $commands);
    }

    private function entry(string $project, string $command): AuditEntry
    {
        return new AuditEntry(new \DateTimeImmutable('2026-11-05T10:00:00+00:00'), $project, $command, Verdict::Plain, Outcome::RanOnce, exitCode: 0);
    }
}
