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
        for ($retry = 0;; ++$retry) {
            try {
                return $read($answer);
            } catch (ServerException|TransportExceptionInterface $e) {
                if ($retry >= $this->retries) {
                    throw $e;
                }
                $answer = $ask();
            }
        }
    }
}
