<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Text an agent wrote, such as a command, made safe to print in the user's terminal: control and format characters
 * (escape sequences, bells, bidirectional overrides) are shown as `?`, so they cannot clear the screen, set its title
 * or reorder what is read; and console tags are shown as they are, not styled.
 */
final class TerminalText
{
    public static function of(string $text): string
    {
        return OutputFormatter::escape(self::visible($text));
    }

    public static function shortened(string $text, int $width): string
    {
        return OutputFormatter::escape(mb_strimwidth(self::visible($text), 0, $width, '…'));
    }

    private static function visible(string $text): string
    {
        return preg_replace('/[\p{Cc}\p{Cf}]/u', '?', mb_scrub($text, 'UTF-8')) ?? '?';
    }
}
