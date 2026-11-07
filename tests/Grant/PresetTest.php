<?php

declare(strict_types=1);

namespace App\Tests\Grant;

use App\Grant\Preset;
use App\Policy\CommandPolicy;
use App\Policy\GitCommands;
use App\Policy\SecretFiles;
use App\Policy\Verdict;
use App\Shell\ShellParser;
use App\Tests\Policy\InMemoryGitAliases;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PresetTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function presetCommands(): iterable
    {
        foreach (Preset::cases() as $preset) {
            foreach ($preset->commands() as $words) {
                $command = implode(' ', $words);
                yield $preset->value.': '.$command => [$command];
            }
        }
    }

    #[DataProvider('presetCommands')]
    public function testAPresetCommandIsOneAGrantCanCover(string $command): void
    {
        $parser = new ShellParser();
        $assessment = new CommandPolicy(new SecretFiles(), new GitCommands(new InMemoryGitAliases(), $parser))->assess($parser->parse($command));

        self::assertSame(Verdict::Plain, $assessment->verdict, \sprintf('%s is asked about every time: a grant for it would never apply.', $command));
    }

    #[DataProvider('presetCommands')]
    public function testAPresetCommandNeitherInstallsNorReachesTheNetwork(string $command): void
    {
        self::assertDoesNotMatchRegularExpression('/\b(install|ci|add|update|require|upgrade|publish|deploy|push|curl|wget)\b/', $command);
    }
}
