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
    }

    public function testTheHashIsTheFilesContent(): void
    {
        $sources = new CommandSources(new ProjectDirectory(self::PROJECT))->of(new ShellParser()->parse('make test'));

        self::assertEquals([[new Source('Makefile', hash('sha256', "Makefile\n"))]], $sources);
    }
}
