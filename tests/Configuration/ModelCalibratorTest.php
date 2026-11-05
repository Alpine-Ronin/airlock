<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use App\Configuration\ModelCalibrator;
use App\Tests\Output\RecordedModelApi;
use App\Tests\Output\RecordedResponses;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;

final class ModelCalibratorTest extends TestCase
{
    public function testAModelThatFlagsTheInjectionAndPassesTheRestIsFitForBothChecks(): void
    {
        $calibration = $this->calibrator()->calibrate(RecordedResponses::BASE_URL, 'test-key', 'test/output-check');

        self::assertTrue($calibration->passesOutputCheck());
        self::assertTrue($calibration->passesRiskLine());
    }

    public function testAModelThatMissesTheInjectionIsUnfitForTheOutputCheck(): void
    {
        $calibration = $this->calibrator()->calibrate(RecordedResponses::BASE_URL, 'test-key', RecordedModelApi::BLIND);

        self::assertSame('it missed a known prompt injection', $calibration->outputCheckFailure);
        self::assertTrue($calibration->passesRiskLine());
    }

    public function testAModelTheApiDoesNotServeIsUnfitForBoth(): void
    {
        $calibration = $this->calibrator()->calibrate(RecordedResponses::BASE_URL, 'test-key', RecordedModelApi::MISSING);

        self::assertFalse($calibration->passesOutputCheck());
        self::assertFalse($calibration->passesRiskLine());
        self::assertSame('the model could not be reached or gave no usable answer', $calibration->outputCheckFailure);
        self::assertTrue($calibration->isUnanswered());
    }

    public function testARateLimitSaysNothingAboutTheModel(): void
    {
        $calibration = $this->calibrator()->calibrate(RecordedResponses::BASE_URL, 'test-key', RecordedModelApi::LIMITED);

        self::assertTrue($calibration->isRateLimited());
        self::assertSame("the model's rate limit was reached", $calibration->outputCheckFailure);
    }

    public function testARateLimitOnThePlainWordsLineSaysNothingAboutTheModel(): void
    {
        $calibration = $this->calibrator()->calibrate(RecordedResponses::BASE_URL, 'test-key', RecordedModelApi::LIMITED_LINE);

        self::assertTrue($calibration->isRateLimited(), 'A rate limit is not a model that gives no short line.');
        self::assertTrue($calibration->passesOutputCheck());
    }

    public function testAModelThatDoesNotAnswerInTimeIsUnanswered(): void
    {
        $calibration = $this->calibrator()->calibrate(RecordedResponses::BASE_URL, 'test-key', RecordedModelApi::SLOW);

        self::assertSame('the model did not answer in time', $calibration->outputCheckFailure);
        self::assertTrue($calibration->isUnanswered(), 'A model that does not answer in time does not count as one that answers.');
    }

    public function testAPlainWordsLineNotWrittenInTimeSaysSo(): void
    {
        $calibration = $this->calibrator()->calibrate(RecordedResponses::BASE_URL, 'test-key', RecordedModelApi::SLOW_LINE);

        self::assertTrue($calibration->passesOutputCheck());
        self::assertSame('the model did not answer in time', $calibration->riskLineFailure);
    }

    private function calibrator(): ModelCalibrator
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new PlatformSubscriber());

        return new ModelCalibrator(new MockHttpClient(new RecordedModelApi(RecordedResponses::BASE_URL)), $dispatcher);
    }
}
