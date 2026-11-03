<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class VersionTest extends TestCase
{
    public function testTheCommandLineGivesAirlocksNameAndVersion(): void
    {
        $process = new Process([\PHP_BINARY, __DIR__.'/../../bin/console', '--version', '--no-ansi'], env: ['APP_ENV' => 'test']);

        $process->mustRun();

        self::assertStringStartsWith(\sprintf('Airlock %s ', InstalledVersions::getRootPackage()['pretty_version']), $process->getOutput());
    }
}
