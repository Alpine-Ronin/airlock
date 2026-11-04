<?php

declare(strict_types=1);

namespace App\Tests\Shell;

use App\Shell\Lexer;
use App\Shell\ShellParser;
use App\Shell\SimpleCommand;
use App\Shell\Token;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Random lines, read by the parser and run by bash: every command bash runs must be one the parser read, word for word.
 *
 * A grant covers a line by the commands the parser reads in it, so a command bash runs that the parser did not read is
 * one no grant was given for. Lines the parser calls opaque are asked about every time and are not compared. Expansions
 * (variables, globs, braces, a tilde) are left out, and lines holding one skipped: their words depend on where the
 * line runs.
 */
final class ShellParserFuzzTest extends TestCase
{
    private const string DIRECTORY = __DIR__.'/../../var/test-shell-fuzz';

    private const int LINES_PER_SEED = 250;

    /**
     * bash runs this handler for every program it cannot find, and none can be found, builtins turned off but the
     * handler's own: it writes the words it was given, in one write so that commands running at once do not mix theirs,
     * and exits with the status the run gives every command, so that each run takes one branch of `&&` and `||`.
     */
    private const string RECORDER = <<<'BASH'
        command_not_found_handle() { local record; printf -v record '%s\037' "$@"; printf '%s\036' "$record" >> "$AIRLOCK_FUZZ_LOG"; return "$AIRLOCK_FUZZ_STATUS"; }
        PATH=/nonexistent
        for builtin in $(compgen -b); do case $builtin in enable|local|printf|return) ;; *) enable -n "$builtin" ;; esac; done
        BASH;

    private const array PROGRAMS = ['make', 'rm', 'p', 'q', 'npm', 'php'];

    private const array WORDS = [
        'test', 'a', 'b', '-rf', '--x', 'x=1', 'A=1', 'B+=2', 'bin/console', '=', '%', '@', ',', '.', ':',
        "'a b'", "'a;b'", "'\$x'", "'*'", "''", '""', '"a b"', '"a\"b"', '"a\\\\b"', '"\$"', '"\\$x"', '"a\\b"',
        '\\ ', '\\;', '\\$x', '\\*', '\\#', '\\{', '\\~', '\\\\', '\\"', "\\'", '\\&', '\\|', '\\>', "\\\n",
        'a#b', 'x\\', 'a"b"c', "a'b'c", '"a"\'b\'', '!', '{', '}', '-',
    ];

    private const array OPERATORS = [
        ' ', ' ', ' ', ' ', ';', ' ; ', '&&', ' && ', '||', ' | ', '|', '|&', ' & ', "\n", '(', ')', ' ( ', ' ) ',
        '{ ', ' }', ' ; } ', '! ', ' #c ', ' >f ', '>f', ' 2>&1 ', ' <f ', ' >>f ', ' &>f ', ' <<<w ', ' >|f ', ' 2>f ',
        ' <>f ', '2>&1', ' >&2 ', ' 1>f ',
    ];

    private Filesystem $filesystem;

    #[\Override]
    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->filesystem->remove(self::DIRECTORY);
        $this->filesystem->mkdir(self::DIRECTORY);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->filesystem->remove(self::DIRECTORY);
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
    public function testEveryCommandBashRunsIsOneTheParserRead(int $seed): void
    {
        $randomizer = new Randomizer(new Xoshiro256StarStar($seed));
        $parser = new ShellParser();

        for ($i = 0; $i < self::LINES_PER_SEED; ++$i) {
            $line = $this->randomLine($randomizer);
            $parsed = $parser->parse($line);
            if ($parsed->isOpaque() || $this->expands($line)) {
                continue;
            }
            $read = array_map(static fn (SimpleCommand $command): array => $command->programWords(), $parsed->commands);

            foreach ([...$this->commandsBashRuns($line, status: 0), ...$this->commandsBashRuns($line, status: 1)] as $ran) {
                self::assertContains($ran, $read, \sprintf('bash runs %s, which the parser did not read in the line %s (seed %d). It read: %s', json_encode($ran), json_encode($line), $seed, json_encode($read)));
            }
        }
    }

    /**
     * Whether a word of the line holds an expansion, which the words joined at random can write unescaped. A bare `{`
     * expands nothing: it opens a group, or is a word like any other.
     */
    private function expands(string $line): bool
    {
        return array_any(new Lexer($line)->tokens(), static fn (Token $token): bool => !$token->isLiteral && !self::isBareBrace($token));
    }

    private static function isBareBrace(Token $token): bool
    {
        return '{' === $token->text && !$token->isQuoted();
    }

    public function testEachBranchOfAConditionRunsOnce(): void
    {
        $line = "test '' && rm a || rm b";

        self::assertSame([['test', ''], ['rm', 'a']], $this->commandsBashRuns($line, status: 0));
        self::assertSame([['test', ''], ['rm', 'b']], $this->commandsBashRuns($line, status: 1), 'The commands after a failing one are compared too.');
    }

    private function randomLine(Randomizer $randomizer): string
    {
        $line = '';
        $parts = $randomizer->getInt(1, 10);
        for ($part = 0; $part < $parts; ++$part) {
            $line .= match ($randomizer->getInt(0, 3)) {
                0 => self::PROGRAMS[$randomizer->getInt(0, \count(self::PROGRAMS) - 1)],
                1, 2 => self::WORDS[$randomizer->getInt(0, \count(self::WORDS) - 1)],
                default => self::OPERATORS[$randomizer->getInt(0, \count(self::OPERATORS) - 1)],
            };
            if (0 === $randomizer->getInt(0, 2)) {
                $line .= ' ';
            }
        }

        return $line;
    }

    /**
     * @return list<list<string>>
     */
    private function commandsBashRuns(string $line, int $status): array
    {
        $this->filesystem->remove(self::DIRECTORY);
        $log = self::DIRECTORY.'/commands.log';
        $this->filesystem->dumpFile($log, '');
        $process = new Process(['bash', '-c', self::RECORDER."\n".$line], self::DIRECTORY, ['AIRLOCK_FUZZ_LOG' => $log, 'AIRLOCK_FUZZ_STATUS' => (string) $status], timeout: 10);
        $process->run();

        $commands = [];
        foreach (explode("\036", (string) file_get_contents($log)) as $record) {
            if ('' === $record) {
                continue;
            }
            $commands[] = explode("\037", substr($record, 0, -1));
        }

        return $commands;
    }
}
