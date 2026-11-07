<?php

declare(strict_types=1);

namespace App\Tests\Injection;

use App\Grant\GrantRules;
use App\Grant\Grants;
use App\Grant\SessionGrants;
use App\Policy\CommandPolicy;
use App\Policy\PolicyFiles;
use App\Policy\Verdict;
use App\Shell\ShellParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Every command of the public corpus, tests/Fixtures/injection/commands.yaml, goes through Airlock's parser, policy and
 * grants as the server's would, and must be refused or asked about, never run unasked.
 */
final class InjectedCommandsTest extends KernelTestCase
{
    private const string CORPUS = __DIR__.'/../Fixtures/injection/commands.yaml';

    private const string ROOT = __DIR__.'/../../var/test-injected-commands';

    private const string PROJECT = self::ROOT.'/project';

    private Filesystem $filesystem;

    /** @var array<mixed> */
    private array $server;

    /** @var array<mixed> */
    private array $environment;

    #[\Override]
    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->filesystem->remove(self::ROOT);
        $this->filesystem->mkdir([self::ROOT.'/home', self::PROJECT]);
        $this->server = $_SERVER;
        $this->environment = $_ENV;
        $_SERVER['HOME'] = $_ENV['HOME'] = self::ROOT.'/home';
        $_SERVER['XDG_CONFIG_HOME'] = $_ENV['XDG_CONFIG_HOME'] = self::ROOT.'/home/.config';
        $_SERVER['AIRLOCK_PROJECT_DIR'] = $_ENV['AIRLOCK_PROJECT_DIR'] = self::PROJECT;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_ENV = $this->environment;
        $this->filesystem->remove(self::ROOT);
        parent::tearDown();
    }

    /**
     * @param list<string>          $granted
     * @param array<string, string> $files
     * @param array<string, string> $edits
     */
    #[DataProvider('scenarios')]
    public function testAnInjectedCommandIsRefusedOrAskedAbout(string $command, string $expected, array $granted, array $files, array $edits, ?string $policy): void
    {
        foreach ($files as $path => $content) {
            $this->filesystem->dumpFile(self::PROJECT.'/'.$path, $content);
        }
        if (null !== $policy) {
            $this->filesystem->dumpFile(self::PROJECT.'/.airlock.yaml', $policy);
        }
        self::bootKernel();
        $parser = new ShellParser();
        foreach ($granted as $grantedCommand) {
            $this->service(SessionGrants::class)->add($this->service(GrantRules::class)->for($parser->parse($grantedCommand)));
        }
        foreach ($edits as $path => $content) {
            $this->filesystem->dumpFile(self::PROJECT.'/'.$path, $content);
        }

        $outcome = $this->outcome($command);

        self::assertNotSame('runs_unasked', $outcome, 'An injected command must never run without the user being asked.');
        self::assertSame($expected, $outcome);
    }

    /**
     * @return iterable<string, array{string, string, list<string>, array<string, string>, array<string, string>, ?string}>
     */
    public static function scenarios(): iterable
    {
        $corpus = Yaml::parseFile(self::CORPUS);
        $scenarios = \is_array($corpus) && \is_array($corpus['scenarios'] ?? null) ? $corpus['scenarios'] : [];
        foreach ($scenarios as $scenario) {
            if (!\is_array($scenario)) {
                continue;
            }
            yield self::text($scenario, 'id') => [
                self::text($scenario, 'command'),
                self::text($scenario, 'expect'),
                self::texts($scenario['granted'] ?? []),
                self::files($scenario['files'] ?? []),
                self::files($scenario['edits'] ?? []),
                \is_string($scenario['policy'] ?? null) ? $scenario['policy'] : null,
            ];
        }
    }

    private function outcome(string $command): string
    {
        $line = new ShellParser()->parse($command);
        $assessment = $this->service(CommandPolicy::class)->assess($line, $this->service(PolicyFiles::class)->refusals());

        return match ($assessment->verdict) {
            Verdict::Refused => 'refused',
            Verdict::AskEveryTime => 'asked_every_time',
            Verdict::Plain => $this->service(Grants::class)->covers($line) ? 'runs_unasked' : 'asked',
        };
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /**
     * @param array<mixed> $scenario
     */
    private static function text(array $scenario, string $key): string
    {
        $value = $scenario[$key] ?? null;
        if (!\is_string($value)) {
            throw new \UnexpectedValueException(\sprintf('A scenario of %s has no "%s" text.', self::CORPUS, $key));
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function texts(mixed $values): array
    {
        return \is_array($values) ? array_values(array_filter($values, \is_string(...))) : [];
    }

    /**
     * @return array<string, string>
     */
    private static function files(mixed $files): array
    {
        $byPath = [];
        foreach (\is_array($files) ? $files : [] as $path => $content) {
            if (\is_string($content)) {
                $byPath[(string) $path] = $content;
            }
        }

        return $byPath;
    }
}
