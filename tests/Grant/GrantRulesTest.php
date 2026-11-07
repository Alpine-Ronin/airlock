<?php

declare(strict_types=1);

namespace App\Tests\Grant;

use App\Execution\ProjectDirectory;
use App\Grant\CommandSources;
use App\Grant\Grant;
use App\Grant\GrantRules;
use App\Shell\ShellParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GrantRulesTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('lines')]
    public function testAGrantCoversTheProgramAndItsFirstArgumentOrExactlyTheCommand(string $line, array $expected): void
    {
        $grants = new GrantRules(new CommandSources(new ProjectDirectory(__DIR__)))->for(new ShellParser()->parse($line));

        self::assertSame($expected, array_map(static fn (Grant $grant): string => $grant->describe(), $grants));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function lines(): iterable
    {
        yield 'a program and its first argument, then anything' => ['make test FILTER=x', ['make test …']];
        yield 'a script by its path' => ['php bin/console cache:clear', ['php bin/console …']];
        yield 'one grant per command of the line' => ['cd src && make test', ['cd src …', 'make test …']];
        yield 'the same grant once' => ['make test; make test', ['make test …']];
        yield 'an option first: exactly that command' => ['ls -la', ['ls -la']];
        yield 'a single word: exactly that' => ['make', ['make']];
        yield 'git: exactly that, its arguments decide what it does' => ['git checkout main', ['git checkout main']];
        yield 'a program that runs others: exactly that' => ['timeout 5 make test', ['timeout 5 make test']];
        yield 'curl: exactly that' => ['curl https://example.com', ['curl https://example.com']];
        yield 'an interpreter running a named command: exactly that' => ['php artisan migrate', ['php artisan migrate']];
        yield 'variables set for it stay part of it' => ['APP_ENV=test php bin/console x', ['APP_ENV=test php bin/console …']];
        yield 'a word with spaces is shown quoted' => ['echo "a b"', ["echo 'a b' …"]];
        yield 'a literal star is shown quoted, so it cannot read as a wildcard' => ["ls '*'", ["ls '*' …"]];
        yield 'a redirection alone grants nothing' => ['> out.txt', []];
    }

    #[DataProvider('coverage')]
    public function testWhatAGrantCovers(string $granted, string $asked, bool $expected): void
    {
        $parser = new ShellParser();
        $grant = new GrantRules(new CommandSources(new ProjectDirectory(__DIR__)))->for($parser->parse($granted))[0];

        self::assertSame($expected, $grant->covers($parser->parse($asked)->commands[0]));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function coverage(): iterable
    {
        yield 'more arguments' => ['make test', 'make test FILTER=x', true];
        yield 'the same command' => ['make test', 'make test', true];
        yield 'another target' => ['make test', 'make deploy', false];
        yield 'a word that only starts the same' => ['make test', 'make tests', false];
        yield 'an exact grant and more arguments' => ['ls -la', 'ls -la /etc', false];
        yield 'a variable set first, not in the grant' => ['make test', 'LD_PRELOAD=/tmp/x.so make test', false];
        yield 'the program alone' => ['make test', 'make', false];
    }
}
