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
     * The messages a request ends with when its time is over: the HTTP client's idle timeout and total deadline, and
     * curl's, which enforces the total deadline when it does the transfer.
     */
    private const array TIMEOUT_MESSAGES = ['Idle timeout reached for ', 'Max duration was reached for ', 'Operation timed out after ', 'Connection timed out after '];

    /**
     * The HTTP client throws its own timeout once, then a plain transport exception holding only its message each time
     * the response is read again, which is how the model platform reads it; curl's timeouts arrive as transport
     * exceptions too. The message is all that tells them apart from a model that cannot be reached.
     */
    private static function isTimeout(\Throwable $failure): bool
    {
        if ($failure instanceof TimeoutExceptionInterface) {
            return true;
        }

        if (!$failure instanceof TransportExceptionInterface) {
            return false;
        }

        return array_any(self::TIMEOUT_MESSAGES, static fn (string $message): bool => str_starts_with($failure->getMessage(), $message));
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
