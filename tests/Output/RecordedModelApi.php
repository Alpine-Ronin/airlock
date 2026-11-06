<?php

declare(strict_types=1);

namespace App\Tests\Output;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The model API as the server sees it in the test environment: every request to the configured API gets a recorded
 * answer, chosen by the structured output it asks for and the model, and nothing reaches the network. A test can make a
 * model take its time: the clock moves on before each of its answers.
 */
final class RecordedModelApi
{
    /** A listed model the API answers 404 for. */
    public const string MISSING = 'test/missing';

    /** A listed model that never sees an injection. */
    public const string BLIND = 'test/blind';

    /** A model the API always answers 429 for: its rate limit is reached. */
    public const string LIMITED = 'test/limited';

    /** A model that checks outputs well but whose rate limit stops it before the plain-words line. */
    public const string LIMITED_LINE = 'test/limited-line';

    /** A model that never answers in time. */
    public const string SLOW = 'test/slow';

    /** A model that checks outputs well but does not write the plain-words line in time. */
    public const string SLOW_LINE = 'test/slow-line';

    /** @var array<string, positive-int> */
    private array $secondsPerAnswer = [];

    public function __construct(
        #[Autowire(env: 'model_setting:AIRLOCK_AI_BASE_URL')]
        private readonly string $baseUrl,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param positive-int $seconds
     */
    public function answerAfter(string $model, int $seconds): void
    {
        $this->secondsPerAnswer[$model] = $seconds;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): ResponseInterface
    {
        if ('GET' === $method && $url === $this->baseUrl.'/v1/models') {
            return RecordedResponses::fixture('models');
        }
        if ($url !== $this->baseUrl.'/v1/chat/completions') {
            return new MockResponse('{"error":{"message":"Not the configured model API."}}', ['http_code' => 404]);
        }
        $request = json_decode(\is_string($options['body'] ?? null) ? $options['body'] : '', true);
        $model = RecordedResponses::at($request, 'model');
        if (\is_string($model) && isset($this->secondsPerAnswer[$model])) {
            $this->clock->sleep($this->secondsPerAnswer[$model]);
        }
        if (self::MISSING === $model) {
            return new MockResponse('{"status":404,"title":"Not Found"}', ['http_code' => 404]);
        }
        if (self::LIMITED === $model) {
            return RecordedResponses::fixture('rate-limited', 429);
        }
        $schema = RecordedResponses::at($request, 'response_format', 'json_schema', 'name');
        if (self::LIMITED_LINE === $model && 'PlainWords' === $schema) {
            return RecordedResponses::fixture('rate-limited', 429);
        }
        if (self::SLOW === $model || (self::SLOW_LINE === $model && 'PlainWords' === $schema)) {
            return new MockResponse(['']);
        }
        $content = RecordedResponses::at($request, 'messages', 1, 'content');
        $content = \is_string($content) ? $content : '';
        $seesInjection = self::BLIND !== $model && 1 === preg_match('/AI (coding )?(assistants|agents)|ignore (all )?previous instructions/i', $content);

        return match ($schema) {
            'InjectionVerdict' => RecordedResponses::fixture($seesInjection ? 'output-check-injection' : 'output-check-clean'),
            'PlainWords' => RecordedResponses::fixture('plain-words'),
            default => new MockResponse('{"error":{"message":"No recorded answer for this request."}}', ['http_code' => 400]),
        };
    }
}
