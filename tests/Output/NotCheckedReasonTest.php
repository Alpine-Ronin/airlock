<?php

declare(strict_types=1);

namespace App\Tests\Output;

use App\Output\NotCheckedReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\Yaml\Yaml;

final class NotCheckedReasonTest extends TestCase
{
    private const string MODEL_CLIENT_CONFIG = __DIR__.'/../../config/packages/ai_generic_platform.yaml';

    private const string URL = 'https://models.example.test/v1/chat/completions';

    /**
     * What a request to a model that answers too late ends with, as the HTTP client and curl word it.
     *
     * @return iterable<string, array{\Throwable}>
     */
    public static function timeouts(): iterable
    {
        yield 'the HTTP client timing out' => [new TimeoutException('Idle timeout reached for "'.self::URL.'".')];
        yield 'its idle timeout, read again' => [new TransportException('Idle timeout reached for "'.self::URL.'".')];
        yield 'its total deadline' => [new TransportException('Max duration was reached for "'.self::URL.'".')];
        yield 'curl, resolving the host' => [new TransportException('Resolving timed out after 8001 milliseconds for "'.self::URL.'".')];
        yield 'curl, connecting' => [new TransportException('Connection timed out after 8001 milliseconds for "'.self::URL.'".')];
        yield 'curl, in the TLS handshake' => [new TransportException('SSL connection timeout for "'.self::URL.'".')];
        yield 'curl, before the answer starts' => [new TransportException('Operation timed out after 1001 milliseconds with 0 bytes received for "'.self::URL.'".')];
        yield 'curl, while the answer trickles in' => [new TransportException('Operation timed out after 1000 milliseconds with 2 bytes received for "'.self::URL.'".')];
    }

    #[DataProvider('timeouts')]
    public function testARequestWhoseTimeIsOverDidNotAnswerInTime(\Throwable $failure): void
    {
        self::assertSame(NotCheckedReason::Timeout, NotCheckedReason::forFailure($failure));
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function otherFailures(): iterable
    {
        yield 'a refused connection' => [new TransportException('Failed to connect to 127.0.0.1 port 8765 after 0 ms: Could not connect to server for "'.self::URL.'".')];
        yield 'a server error' => [new ServerException(503, 'Service Unavailable')];
        yield 'an answer that is not a verdict' => [new \UnexpectedValueException('The model answered with null, not a verdict.')];
    }

    #[DataProvider('otherFailures')]
    public function testAnyOtherFailureIsAModelThatCouldNotBeReached(\Throwable $failure): void
    {
        self::assertSame(NotCheckedReason::Error, NotCheckedReason::forFailure($failure));
    }

    public function testARateLimitIsOne(): void
    {
        self::assertSame(NotCheckedReason::RateLimited, NotCheckedReason::forFailure(new RateLimitExceededException()));
    }

    public function testTheModelClientBoundsBothTheWaitForAnAnswerAndTheWholeAnswer(): void
    {
        $client = Yaml::parseFile(self::MODEL_CLIENT_CONFIG);
        foreach (['framework', 'http_client', 'scoped_clients', 'models.client'] as $key) {
            self::assertIsArray($client);
            $client = $client[$key] ?? null;
        }
        self::assertIsArray($client);

        self::assertSame('%env(float:AIRLOCK_AI_TIMEOUT)%', $client['timeout'] ?? null, 'An answer that does not start is not waited for.');
        self::assertSame('%env(float:AIRLOCK_AI_TIMEOUT)%', $client['max_duration'] ?? null, 'An answer that trickles in is not waited for either.');
    }
}
