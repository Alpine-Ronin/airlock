<?php

declare(strict_types=1);

namespace App\Network;

/**
 * One JSON object per decision, appended to a file the runner reads once the command is over.
 */
final readonly class JsonLinesDecisionLog implements DecisionLog
{
    public function __construct(
        private string $file,
    ) {
    }

    #[\Override]
    public function record(ProxyTarget $target, ProxyDecision $decision): void
    {
        $line = json_encode(['host' => $target->host, 'port' => $target->port, 'decision' => $decision->value], \JSON_THROW_ON_ERROR);
        if (false === file_put_contents($this->file, $line."\n", \FILE_APPEND | \LOCK_EX)) {
            throw new \RuntimeException(\sprintf('Airlock cannot write the proxy\'s decisions to %s.', $this->file));
        }
    }
}
