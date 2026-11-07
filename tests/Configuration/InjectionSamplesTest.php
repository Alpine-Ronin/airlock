<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use App\Configuration\InjectionSample;
use App\Configuration\InjectionSamples;
use App\Configuration\InvalidSamplesException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class InjectionSamplesTest extends TestCase
{
    private const string FILE = __DIR__.'/../../var/test-injection-samples.yaml';

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove(self::FILE);
    }

    public function testThePublishedCorpusHoldsInjectionsAndCleanOutputs(): void
    {
        $samples = new InjectionSamples()->read(__DIR__.'/../Fixtures/injection/outputs.yaml');

        $injections = array_filter($samples, static fn (InjectionSample $sample): bool => $sample->isInjection);
        self::assertCount(10, $injections);
        self::assertCount(18, $samples, 'Eight clean outputs, some telling a human what to do.');
    }

    public function testAnEntryWithoutItsKindIsRefused(): void
    {
        new Filesystem()->dumpFile(self::FILE, "samples:\n  - { id: x, output: y }\n");

        $this->expectException(InvalidSamplesException::class);
        $this->expectExceptionMessageIsOrContains('is not {id, injection: true|false, output}');

        new InjectionSamples()->read(self::FILE);
    }

    public function testAFileThatDoesNotExistIsRefused(): void
    {
        $this->expectException(InvalidSamplesException::class);

        new InjectionSamples()->read(self::FILE);
    }
}
