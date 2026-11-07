<?php

declare(strict_types=1);

namespace App\Tests\Grant;

use App\Execution\ProjectDirectory;
use App\Grant\CommandSources;
use App\Grant\Source;
use App\Shell\ShellParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class CommandSourcesTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../var/test-command-sources';

    private const string PROJECT = self::ROOT.'/project';

    private const array FILES = [
        'Makefile', 'src/Makefile', 'tools/Makefile', 'build.mk', 'package.json', 'web/package.json', 'composer.json',
        'bin/console', 'build.sh', 'artisan',
    ];

    private Filesystem $filesystem;

    #[\Override]
    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->filesystem->remove(self::ROOT);
        foreach (self::FILES as $file) {
            $this->filesystem->dumpFile(self::PROJECT.'/'.$file, $file."\n");
        }
        $this->filesystem->dumpFile(self::ROOT.'/outside.sh', "echo outside\n");
        $this->filesystem->dumpFile(self::PROJECT.'/a/Makefile', "a/Makefile\n");
        $this->filesystem->mkdir(self::PROJECT.'/a/b');
        $this->filesystem->symlink('a/b', self::PROJECT.'/link');
        $this->filesystem->symlink('Makefile', self::PROJECT.'/alias');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->filesystem->remove(self::ROOT);
    }

    /**
     * @param list<list<string>> $expected
     */
    #[DataProvider('lines')]
    public function testEachCommandReadsTheProjectFilesThatDecideWhatItDoes(string $line, array $expected): void
    {
        $sources = new CommandSources(new ProjectDirectory(self::PROJECT))->of(new ShellParser()->parse($line));

        self::assertSame($expected, array_map(static fn (array $command): array => array_map(static fn (Source $source): string => $source->path, $command), $sources));
    }

    /**
     * @return iterable<string, array{string, list<list<string>>}>
     */
    public static function lines(): iterable
    {
        yield 'make reads its Makefile' => ['make test', [['Makefile']]];
        yield 'make -C reads the one there' => ['make -C tools test', [['tools/Makefile']]];
        yield 'make -f reads the one named' => ['make -f build.mk', [['build.mk']]];
        yield 'after cd, the Makefile there too' => ['cd src && make test', [[], ['Makefile', 'src/Makefile']]];
        yield 'a package manager reads package.json' => ['npm run build', [['package.json']]];
        yield 'npm --prefix reads the one there' => ['npm --prefix web run build', [['web/package.json']]];
        yield 'composer reads composer.json' => ['composer test', [['composer.json']]];
        yield 'an interpreter reads its script' => ['php bin/console cache:clear', [['bin/console']]];
        yield 'an interpreter reads a script named without a path' => ['php artisan migrate', [['artisan']]];
        yield 'a program run by its path is its own source' => ['./build.sh --fast', [['build.sh']]];
        yield 'variables set for it change nothing' => ['APP_ENV=test php bin/console x', [['bin/console']]];
        yield 'a program from the system reads nothing of the project' => ['ls -la', [[]]];
        yield 'an interpreter running code reads no file' => ['php -r "echo 1;"', [[]]];
        yield 'a file outside the project does not count: no command can write there' => ['sh ../outside.sh', [[]]];
        yield 'a file that does not exist is not one' => ['sh missing.sh', [[]]];
        yield 'make by its path still reads its Makefile' => ['/usr/bin/make test', [['Makefile']]];
        yield 'a wrapped make reads its Makefile' => ['timeout 5 make test', [['Makefile']]];
        yield 'a script after interpreter options' => ['sh -x build.sh', [['build.sh']]];
        yield 'make -C clustered with other options' => ['make -sC tools test', [['tools/Makefile']]];
        yield 'every make -C in turn' => ['make -C src -C ../tools test', [['tools/Makefile']]];
        yield 'every make -f' => ['make -f build.mk -f Makefile', [['build.mk', 'Makefile']]];
        yield 'cd past its options' => ['cd -P tools && make test', [[], ['Makefile', 'tools/Makefile']]];
        yield 'cd past --' => ['cd -- src && make', [[], ['Makefile', 'src/Makefile']]];
        yield 'code given inline is not followed' => ['bash -c "sh build.sh"', [[]]];
        yield 'env -C moves the program it runs' => ['env -C tools make test', [['Makefile', 'tools/Makefile']]];
        yield 'env -C clustered with other options' => ['env -iC tools make test', [['Makefile', 'tools/Makefile']]];
        yield 'an option after the script is the script\'s own' => ['php bin/console app:sync -e prod', [['bin/console']]];
        yield '.. after a symbolic link leads where the link points' => ['make -C link/.. test', [['a/Makefile']]];
    }

    #[DataProvider('linesThatCanChangeASourceFirst')]
    public function testALineThatCanChangeASourceBeforeItIsReadIsSaidSo(string $line, bool $expected): void
    {
        $sources = new CommandSources(new ProjectDirectory(self::PROJECT));

        self::assertSame($expected, $sources->canChangeBeforeRead(new ShellParser()->parse($line)));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function linesThatCanChangeASourceFirst(): iterable
    {
        yield 'a command before make can replace its Makefile' => ['cp build.mk Makefile && make test', true];
        yield 'a redirection before make can write its Makefile' => ['echo all: > Makefile; make', true];
        yield 'make writing its own Makefile' => ['make test > Makefile', true];
        yield 'a script the line creates' => ['touch new.sh; sh new.sh', true];
        yield 'a command later in make\'s pipeline' => ['make test | cp build.mk Makefile', true];
        yield 'a command after make sent to the background' => ['make test & cp build.mk Makefile', true];
        yield 'a command after make\'s pipeline sent to the background' => ['make test | cat & cp build.mk Makefile', true];
        yield 'make writing its own Makefile by another spelling' => ['make test > src/../Makefile', true];
        yield 'make writing its own Makefile through a symbolic link' => ['make test > alias', true];
        yield 'a command after a list holding make, sent to the background' => ['make test && ls & cp build.mk Makefile', true];
        yield 'a command after an or-list holding make, sent to the background' => ['make test || ls & cp build.mk Makefile', true];
        yield 'a command after make has finished' => ['make test && cp build.mk Makefile', false];
        yield 'only a cd before make' => ['cd src && make test', false];
        yield 'make writing elsewhere' => ['make test > out.log', false];
        yield 'make first' => ['make test && ls', false];
        yield 'nothing reads a project file' => ['ls && echo hi', false];
    }

    public function testTheHashIsTheFilesContent(): void
    {
        $sources = new CommandSources(new ProjectDirectory(self::PROJECT))->of(new ShellParser()->parse('make test'));

        self::assertEquals([[new Source('Makefile', hash('sha256', "Makefile\n"))]], $sources);
    }
}
