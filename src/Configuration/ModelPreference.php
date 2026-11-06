<?php

declare(strict_types=1);

namespace App\Configuration;

/**
 * Which of the models that pass a calibration `configure` keeps: the first calibrated, or the fastest.
 */
enum ModelPreference
{
    case First;
    case Fastest;

    public function prefers(Calibration $candidate, ?Calibration $kept): bool
    {
        if (!$kept instanceof Calibration) {
            return true;
        }

        return match ($this) {
            self::First => false,
            self::Fastest => $candidate->seconds < $kept->seconds,
        };
    }
}
