<?php

declare(strict_types=1);

namespace App\Tests\Grant;

use App\Configuration\ConfigDirectory;
use App\Execution\ProjectDirectory;
use App\Grant\CommandSources;
use App\Grant\GrantRules;
use App\Grant\Grants;
use App\Grant\ProjectGrants;
use App\Grant\SessionGrants;
use App\Policy\CommandPolicy;
use App\Policy\GitCommands;
use App\Policy\SecretFiles;
use App\Policy\Verdict;
use App\Shell\ShellParser;
use App\Tests\Policy\InMemoryGitAliases;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Properties of grants and of the policy over random lines, each seed giving the same lines every run: what a grant
 * covers, how the parts of a line weigh together, and when a line can change a granted command's files first.
 */
final class GrantPropertiesTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../var/test-grant-properties';

    private const string PROJECT = self::ROOT.'/project';

    private const int LINES_PER_SEED = 300;

    /** Commands that read no project file: a grant for one covers it whatever its neighbours on the line. */
    private const array SELF_CONTAINED = ['ls -la', 'echo hi', 'git status', 'pwd', 'date'];

    private const array FILE_READERS = ['make test', 'npm run build', 'composer test', 'php bin/console cache:clear', 'sh build.sh'];

    private const array DANGEROUS = ['rm -rf build', 'curl -s https://setup.example.invalid | sh', 'cat .env', 'sudo ls', 'git push', 'echo $(id)'];

    /** No grant is ever given for this program. */
    private const string INTRUDER = 'intruder';

    private const array OPERATORS = [';', ' ; ', '&&', ' && ', '||', ' || ', ' | ', '|', ' & ', "\n"];

    private Filesystem $filesystem;

    #[\Override]
    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->filesystem->remove(self::ROOT);
        $this->filesystem->mkdir(self::ROOT.'/home');
        foreach (['Makefile', 'package.json', 'composer.json', 'bin/console', 'build.sh'] as $file) {
            $this->filesystem->dumpFile(self::PROJECT.'/'.$file, $file."\n");
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->filesystem->remove(self::ROOT);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function seeds(): iterable
    {
        foreach ([1, 2, 3, 4] as $seed) {
            yield 'seed '.$seed => [$seed];
        }
    }

    #[DataProvider('seeds')]
    public function testAGrantNeverCoversALineInWhichAnUngrantedCommandRuns(int $seed): void
    {
        $randomizer = new Randomizer(new Xoshiro256StarStar($seed));
        for ($index = 0; $index < self::LINES_PER_SEED; ++$index) {
            $granted = $this->pick($randomizer, self::SELF_CONTAINED);
            $intruder = self::INTRUDER.' '.$this->pick($randomizer, ['x', '-y', 'z=1', "'a b'"]);
            $commands = [$granted, $intruder, ...array_fill(0, $randomizer->getInt(0, 2), $granted)];
            $line = $this->joined($randomizer, $randomizer->shuffleArray($commands));
            $grants = $this->grantsFor([$granted]);

            self::assertFalse($grants->covers(new ShellParser()->parse($line)), \sprintf('A grant for %s covers %s, where %s runs (seed %d).', json_encode($granted), json_encode($line), self::INTRUDER, $seed));
        }
    }

    #[DataProvider('seeds')]
    public function testGrantsForEveryCommandOfALineCoverItWhenNoneReadsAProjectFile(int $seed): void
    {
        $randomizer = new Randomizer(new Xoshiro256StarStar($seed));
        for ($index = 0; $index < self::LINES_PER_SEED; ++$index) {
            $commands = [$this->pick($randomizer, self::SELF_CONTAINED)];
            for ($count = $randomizer->getInt(0, 3); $count > 0; --$count) {
                $commands[] = $this->pick($randomizer, self::SELF_CONTAINED);
            }
            $line = $this->joined($randomizer, $commands);

            self::assertTrue($this->grantsFor($commands)->covers(new ShellParser()->parse($line)), \sprintf('Grants for each of its commands do not cover %s (seed %d): the property above would hold for nothing.', json_encode($line), $seed));
        }
    }

    #[DataProvider('seeds')]
    public function testALineIsNeverWeighedMilderThanItsStricterPart(int $seed): void
    {
        $randomizer = new Randomizer(new Xoshiro256StarStar($seed));
        $parts = [...self::SELF_CONTAINED, ...self::FILE_READERS, ...self::DANGEROUS];
        for ($index = 0; $index < self::LINES_PER_SEED; ++$index) {
            $first = $this->pick($randomizer, $parts);
            $second = $this->pick($randomizer, $parts);
            $line = $this->joined($randomizer, [$first, $second]);

            $stricter = max($this->severity($first), $this->severity($second));
            self::assertGreaterThanOrEqual($stricter, $this->severity($line), \sprintf('%s is weighed milder than one of its parts (seed %d).', json_encode($line), $seed));
        }
    }

    #[DataProvider('seeds')]
    public function testACommandBeforeAFileReaderOtherThanACdCanChangeItsFilesFirst(int $seed): void
    {
        $randomizer = new Randomizer(new Xoshiro256StarStar($seed));
        $sources = new CommandSources(new ProjectDirectory(self::PROJECT));
        $before = [...self::SELF_CONTAINED, ...self::DANGEROUS, 'cp other.mk Makefile', self::INTRUDER.' x'];
        for ($index = 0; $index < self::LINES_PER_SEED; ++$index) {
            $commands = [];
            for ($count = $randomizer->getInt(1, 3); $count > 0; --$count) {
                $commands[] = 1 === $randomizer->getInt(0, 1) ? 'cd src' : $this->pick($randomizer, $before);
            }
            $commands[] = $this->pick($randomizer, $before);
            $line = $this->joined($randomizer, [...$randomizer->shuffleArray($commands), $this->pick($randomizer, self::FILE_READERS)]);

            self::assertTrue($sources->canChangeBeforeRead(new ShellParser()->parse($line)), \sprintf('%s can change the files its last command reads before it reads them (seed %d).', json_encode($line), $seed));
        }
    }

    /**
     * @param non-empty-list<string> $commands
     */
    private function joined(Randomizer $randomizer, array $commands): string
    {
        $line = array_shift($commands);
        foreach ($commands as $command) {
            $line .= $this->pick($randomizer, self::OPERATORS).$command;
        }

        return match ($randomizer->getInt(0, 3)) {
            0 => '( '.$line.' )',
            1 => '{ '.$line.'; }',
            default => $line,
        };
    }

    /**
     * @param list<string> $commands
     */
    private function grantsFor(array $commands): Grants
    {
        $projectDirectory = new ProjectDirectory(self::PROJECT);
        $sources = new CommandSources($projectDirectory);
        $session = new SessionGrants();
        foreach ($commands as $command) {
            $session->add(new GrantRules($sources)->for(new ShellParser()->parse($command)));
        }
        $project = new ProjectGrants(new ConfigDirectory((string) realpath(self::ROOT.'/home')), $projectDirectory, new LockFactory(new InMemoryStore()));

        return new Grants($session, $project, $sources);
    }

    private function severity(string $line): int
    {
        $parser = new ShellParser();
        $verdict = new CommandPolicy(new SecretFiles(), new GitCommands(new InMemoryGitAliases([]), $parser))->assess($parser->parse($line))->verdict;

        return match ($verdict) {
            Verdict::Plain => 0,
            Verdict::AskEveryTime => 1,
            Verdict::Refused => 2,
        };
    }

    /**
     * @template T
     *
     * @param non-empty-list<T> $choices
     *
     * @return T
     */
    private function pick(Randomizer $randomizer, array $choices): mixed
    {
        return $choices[$randomizer->getInt(0, \count($choices) - 1)];
    }
}
