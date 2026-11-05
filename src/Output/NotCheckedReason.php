<?php

declare(strict_types=1);

namespace App\Output;

use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

enum NotCheckedReason: string
{
    case NotConfigured = 'not_configured';
    case Timeout = 'timeout';
    case RateLimited = 'rate_limited';
    case Error = 'error';

    /**
     * Why a model gave no answer, from the exception its request ended with.
     */
    public static function forFailure(\Throwable $failure): self
    {
        return match (true) {
            $failure instanceof RateLimitExceededException => self::RateLimited,
            self::isTimeout($failure) => self::Timeout,
            default => self::Error,
        };
    }

    /**
     * The HTTP client throws its timeout once, then a plain transport exception holding only its message each time the
     * response is read again, which is how the model platform reads it: the message is all that is left of it.
     */
    private static function isTimeout(\Throwable $failure): bool
    {
        if ($failure instanceof TimeoutExceptionInterface) {
            return true;
        }

        return $failure instanceof TransportExceptionInterface && str_starts_with($failure->getMessage(), 'Idle timeout reached for ');
    }

    public function describe(): string
    {
        return match ($this) {
            self::NotConfigured => 'no model API is configured; set one up with `airlock configure`, in a terminal',
            self::Timeout => 'the model did not answer in time',
            self::RateLimited => "the model's rate limit was reached",
            self::Error => 'the model could not be reached or gave no usable answer',
        };
    }
}
