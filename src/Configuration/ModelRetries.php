<?php

declare(strict_types=1);

namespace App\Configuration;

use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Asks a model again when its answer was lost on the way: it did not come in time, the connection failed or the server
 * failed. A model that answers late once usually answers in a few seconds the next time. A rate limit is not asked
 * again, as asking sooner does not lift it, nor an answer that came but cannot be used.
 */
final readonly class ModelRetries
{
    public function __construct(
        #[Autowire(env: 'int:AIRLOCK_AI_RETRIES')]
        private int $retries,
    ) {
    }

    /**
     * Reads the answer, asking again for each one lost, as many times as the retries allow.
     *
     * @template T
     *
     * @param \Closure(): DeferredResult  $ask  asks the model the same question again
     * @param \Closure(DeferredResult): T $read
     *
     * @return T
     *
     * @throws PlatformException|HttpClientException the last failure, when no answer could be read
     */
    public function read(DeferredResult $answer, \Closure $ask, \Closure $read): mixed
    {
        return $this->readAll([$answer], static fn (): DeferredResult => $ask(), $read)[0];
    }

    /**
     * Reads every answer, then asks again, all together, for those that were lost, as many times as the retries allow.
     * Asking again before every answer is read would send a request while others are still unread, which the HTTP
     * client refuses.
     *
     * @template T
     *
     * @param list<DeferredResult>          $answers
     * @param \Closure(int): DeferredResult $ask     asks the model again the question of the answer at that index
     * @param \Closure(DeferredResult): T   $read
     *
     * @return list<T> the answers read, in their order
     *
     * @throws PlatformException|HttpClientException the last failure of the first answer still lost
     */
    public function readAll(array $answers, \Closure $ask, \Closure $read): array
    {
        $values = [];
        $unread = array_keys($answers);
        for ($retry = 0;; ++$retry) {
            $failures = [];
            foreach ($unread as $index) {
                try {
                    $values[$index] = $read($answers[$index]);
                } catch (ServerException|TransportExceptionInterface $e) {
                    $failures[$index] = $e;
                }
            }
            if ([] === $failures) {
                ksort($values);

                return array_values($values);
            }
            if ($retry >= $this->retries) {
                throw array_first($failures);
            }
            $unread = array_keys($failures);
            foreach ($unread as $index) {
                $answers[$index] = $ask($index);
            }
        }
    }
}
