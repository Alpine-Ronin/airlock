<?php

declare(strict_types=1);

namespace App\Configuration;

use App\Output\NotCheckedReason;

/**
 * What one model did on the calibration samples: null where it passed, why it failed otherwise. A rate-limited
 * calibration says nothing about the model: the API stopped answering. An unanswered one is a model the API lists but
 * does not serve, or one that cannot give the answer the checks need, or not in time. The seconds are how long the
 * calibration took, to compare models that pass.
 */
final readonly class Calibration
{
    public function __construct(
        public string $model,
        public float $seconds,
        public ?string $outputCheckFailure,
        public ?string $riskLineFailure,
        public ?NotCheckedReason $notCheckedReason = null,
    ) {
    }

    public function isRateLimited(): bool
    {
        return NotCheckedReason::RateLimited === $this->notCheckedReason;
    }

    public function isUnanswered(): bool
    {
        return \in_array($this->notCheckedReason, [NotCheckedReason::Error, NotCheckedReason::Timeout], true);
    }

    public function passesOutputCheck(): bool
    {
        return null === $this->outputCheckFailure;
    }

    public function passesRiskLine(): bool
    {
        return null === $this->riskLineFailure;
    }
}
