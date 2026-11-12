<?php

declare(strict_types=1);

namespace App\Update;

/**
 * Puts a checked binary in place of the running one. The static PHP runtime reads Airlock's code from its own file, by
 * path, as it needs it: once that file is replaced, this process would read the new binary at the old one's offsets.
 * So this process becomes mv, and no more of Airlock's code runs after the rename. An Airlock still running from the
 * old binary, such as an MCP server, has the same problem: its client has to restart it.
 */
final readonly class RunningBinaryReplacer
{
    private const string MOVE = '/bin/mv';

    /**
     * @throws UpdateFailedException when this process cannot become mv
     */
    public function replace(string $checkedBinary, string $binaryPath): never
    {
        pcntl_exec(self::MOVE, ['-f', $checkedBinary, $binaryPath]);

        throw new UpdateFailedException(\sprintf('Could not run %s to put %s in place of %s.', self::MOVE, $checkedBinary, $binaryPath));
    }
}
