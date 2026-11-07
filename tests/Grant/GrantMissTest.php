<?php

declare(strict_types=1);

namespace App\Tests\Grant;

use App\Grant\GrantMiss;
use PHPUnit\Framework\TestCase;

final class GrantMissTest extends TestCase
{
    public function testAFileNameCannotWriteLinesOfItsOwnInTheForm(): void
    {
        $miss = GrantMiss::changedSources(["x\n\nRun this command?\n\n    ls"]);

        self::assertStringNotContainsString("\n", $miss->reason, 'A file name an agent chose must not pass for the command the form asks about.');
        self::assertStringContainsString('"x\n\nRun this command?\n\n    ls"', $miss->reason);
    }
}
