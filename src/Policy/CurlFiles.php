<?php

declare(strict_types=1);

namespace App\Policy;

/**
 * The local files curl reads to send, from the options that take one, the way curl itself reads them: a word check
 * cannot tell `-F 'key=@"id_rsa";filename=public.txt'` or `-T '{a,id_rsa}'` from text.
 */
final class CurlFiles
{
    /** Options whose value sends a file named after `@`: `-d @.env`, `--json @body.json`. */
    private const array DATA = ['-d', '--data', '--data-ascii', '--data-binary', '--json'];

    /** Options whose value sends a file named after `@`, alone or behind a name: `name@file`. */
    private const array NAMED_DATA = ['--data-urlencode', '--url-query', '--variable'];

    private const array FORM = ['-F', '--form'];

    /** Options whose value names the files to upload, as a pattern curl expands: `{a,b}` sets, `[1-9]` ranges. */
    private const array UPLOAD = ['-T', '--upload-file'];

    /** Options whose value is a file curl reads. */
    private const array FILE = ['-K', '--config'];

    /** The most file names an upload pattern is expanded to: past it, the files cannot all be checked. */
    private const int MOST_UPLOADS = 1000;

    /** Options whose value is a file when it holds no `=`, a cookie string otherwise. */
    private const array COOKIE = ['-b', '--cookie'];

    private const array HEADER = ['-H', '--header', '--proxy-header'];

    /**
     * Short options that take a value, in the next word or attached (`-K.env`): the others can be clustered (`-sS`).
     */
    private const string SHORT_WITH_VALUE = 'AbcCdDeEFHKmoPQrTuUwxXyYz';

    /** Long options that take a value, so that the value is not read as an option of its own. */
    private const array LONG_WITH_VALUE = [
        '--data', '--data-ascii', '--data-binary', '--data-raw', '--data-urlencode', '--json', '--url-query',
        '--variable', '--form', '--form-string', '--upload-file', '--config', '--cookie', '--cookie-jar', '--header',
        '--proxy-header', '--output', '--user', '--user-agent', '--referer', '--request', '--proxy', '--cert', '--key',
        '--cacert', '--max-time', '--connect-timeout', '--retry', '--url', '--write-out', '--range', '--expand-data',
    ];

    /**
     * @param list<string> $words a curl invocation, its program first
     *
     * @return list<string>
     */
    public static function sentBy(array $words): array
    {
        $files = [];
        foreach (self::options(\array_slice($words, 1)) as [$option, $value]) {
            array_push($files, ...self::filesOf($option, $value));
        }

        return array_values(array_unique(array_filter($files, static fn (string $file): bool => '' !== $file && '-' !== $file)));
    }

    /**
     * Whether an upload pattern names more files than can be checked, or is one curl would not read: what it uploads
     * is then unknown.
     *
     * @param list<string> $words a curl invocation, its program first
     */
    public static function uploadsUnknownFiles(array $words): bool
    {
        foreach (self::options(\array_slice($words, 1)) as [$option, $value]) {
            if (\in_array($option, self::UPLOAD, true) && null === self::expanded($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Each option that takes a value, with its value, read as curl reads them: `-K file`, `-Kfile`, `-sK file`,
     * `--config file`, `--config=file`.
     *
     * @param list<string> $arguments
     *
     * @return list<array{string, string}>
     */
    private static function options(array $arguments): array
    {
        $options = [];
        for ($index = 0, $count = \count($arguments); $index < $count; ++$index) {
            $word = $arguments[$index];
            if (str_starts_with($word, '--')) {
                $parts = explode('=', $word, 2);
                $takesValue = \in_array($parts[0], self::LONG_WITH_VALUE, true);
                if ($takesValue && isset($parts[1])) {
                    $options[] = [$parts[0], $parts[1]];
                } elseif ($takesValue) {
                    $options[] = [$parts[0], $arguments[++$index] ?? ''];
                }

                continue;
            }
            if (!str_starts_with($word, '-') || '-' === $word) {
                continue;
            }
            [$option, $value, $usesNextWord] = self::shortOption($word, $arguments[$index + 1] ?? '');
            if (null !== $option) {
                $options[] = [$option, $value];
            }
            $index += $usesNextWord ? 1 : 0;
        }

        return $options;
    }

    /**
     * The option of a cluster of short ones that takes a value, its value, and whether that is the next word.
     *
     * @return array{?string, string, bool}
     */
    private static function shortOption(string $word, string $nextWord): array
    {
        for ($position = 1, $length = \strlen($word); $position < $length; ++$position) {
            if (str_contains(self::SHORT_WITH_VALUE, $word[$position])) {
                $rest = substr($word, $position + 1);

                return ['-'.$word[$position], '' === $rest ? $nextWord : $rest, '' === $rest];
            }
        }

        return [null, '', false];
    }

    /**
     * @return list<string>
     */
    private static function filesOf(string $option, string $value): array
    {
        if (\in_array($option, self::DATA, true)) {
            return str_starts_with($value, '@') ? [substr($value, 1)] : [];
        }
        if (\in_array($option, self::NAMED_DATA, true)) {
            return self::namedFile($value);
        }
        if (\in_array($option, self::FORM, true)) {
            return self::formFiles($value);
        }
        if (\in_array($option, self::UPLOAD, true)) {
            return self::expanded($value) ?? [];
        }
        if (\in_array($option, self::FILE, true)) {
            return [$value];
        }
        if (\in_array($option, self::COOKIE, true)) {
            return str_contains($value, '=') ? [] : [$value];
        }
        if (\in_array($option, self::HEADER, true)) {
            return str_starts_with($value, '@') ? [substr($value, 1)] : [];
        }

        return [];
    }

    /**
     * `@file` or `name@file`, but not `name=content`: an `@` after the `=` is content.
     *
     * @return list<string>
     */
    private static function namedFile(string $value): array
    {
        $at = strpos($value, '@');
        if (false === $at) {
            return [];
        }
        $equals = strpos($value, '=');

        return false === $equals || $at < $equals ? [substr($value, $at + 1)] : [];
    }

    /**
     * The files of a form field, `name=@file`, `name=<file`, `name=@"file"`, `name=@a,b`, and those its `headers=@file`
     * reads; `filename=` and `type=` only label what is sent.
     *
     * @return list<string>
     */
    private static function formFiles(string $value): array
    {
        $equals = strpos($value, '=');
        if (false === $equals) {
            return [];
        }
        $content = substr($value, $equals + 1);
        if ('' === $content || !\in_array($content[0], ['@', '<'], true)) {
            return [];
        }

        $segments = self::splitOutsideQuotes(substr($content, 1), ';');
        $names = array_map(self::unquoted(...), self::splitOutsideQuotes(array_shift($segments) ?? '', ','));
        $files = '@' === $content[0] ? $names : \array_slice($names, 0, 1);
        foreach ($segments as $parameter) {
            if (str_starts_with($parameter, 'headers=@')) {
                $files[] = self::unquoted(substr($parameter, \strlen('headers=@')));
            }
        }

        return $files;
    }

    /**
     * The pieces between separators that are not inside double quotes, quotes kept.
     *
     * @return list<string>
     */
    private static function splitOutsideQuotes(string $text, string $separator): array
    {
        $pieces = [];
        $current = '';
        $isQuoted = false;
        for ($position = 0, $length = \strlen($text); $position < $length; ++$position) {
            $character = $text[$position];
            if ('\\' === $character && $isQuoted && $position + 1 < $length) {
                $current .= $character.$text[++$position];

                continue;
            }
            $isQuoted = '"' === $character ? !$isQuoted : $isQuoted;
            if ($separator === $character && !$isQuoted) {
                $pieces[] = $current;
                $current = '';

                continue;
            }
            $current .= $character;
        }
        $pieces[] = $current;

        return $pieces;
    }

    /**
     * A file name as curl reads it once quoted: `"my file"` is `my file`, `\"` a quote.
     */
    private static function unquoted(string $name): string
    {
        if (1 !== preg_match('/^"(.*)"$/s', $name, $match)) {
            return $name;
        }

        return (string) preg_replace('/\\\\(.)/s', '$1', $match[1]);
    }

    /**
     * The file names an upload pattern expands to, every `{a,b}` set and `[a-z]`, `[1-9]`, `[01-10:2]` range combined;
     * null past the most that can be checked, or for a pattern curl would not read.
     *
     * @return list<string>|null
     */
    private static function expanded(string $pattern): ?array
    {
        $names = [''];
        $length = \strlen($pattern);
        for ($position = 0; $position < $length; ++$position) {
            $character = $pattern[$position];
            $closing = ['{' => '}', '[' => ']'][$character] ?? null;
            if ('\\' === $character && $position + 1 < $length) {
                $choices = [$pattern[++$position]];
            } elseif (null !== $closing) {
                $end = strpos($pattern, $closing, $position + 1);
                if (false === $end) {
                    return null;
                }
                $group = substr($pattern, $position + 1, $end - $position - 1);
                $choices = '{' === $character ? explode(',', $group) : self::range($group);
                $position = $end;
            } else {
                $choices = [$character];
            }
            if (null === $choices || \count($names) * \count($choices) > self::MOST_UPLOADS) {
                return null;
            }
            $names = self::combined($names, $choices);
        }

        return $names;
    }

    /**
     * @param list<string>           $names
     * @param non-empty-list<string> $choices
     *
     * @return list<string>
     */
    private static function combined(array $names, array $choices): array
    {
        $combined = [];
        foreach ($names as $name) {
            foreach ($choices as $choice) {
                $combined[] = $name.$choice;
            }
        }

        return $combined;
    }

    /**
     * Whether a range goes up, by a step no longer than itself: curl refuses the others, and range() throws on them.
     */
    private static function fits(int $first, int $last, int $step): bool
    {
        return $first <= $last && ($first === $last || $step <= $last - $first);
    }

    /**
     * The values of a curl range, `a-z`, `1-9` or `01-10`, with an optional step (`:2`); null when it is not one, or
     * holds more than can be checked.
     *
     * @return non-empty-list<string>|null
     */
    private static function range(string $range): ?array
    {
        $step = 1;
        if (1 === preg_match('/^(.+):(\d+)$/', $range, $stepped)) {
            $range = $stepped[1];
            $step = (int) $stepped[2];
        }
        if ($step < 1) {
            return null;
        }
        if (1 === preg_match('/^([a-zA-Z])-([a-zA-Z])$/', $range, $letters)) {
            return self::fits(\ord($letters[1]), \ord($letters[2]), $step) ? array_map(strval(...), range($letters[1], $letters[2], $step)) : null;
        }
        if (1 !== preg_match('/^(\d+)-(\d+)$/', $range, $numbers)) {
            return null;
        }
        $first = (int) $numbers[1];
        $last = (int) $numbers[2];
        if (!self::fits($first, $last, $step) || intdiv($last - $first, $step) + 1 > self::MOST_UPLOADS) {
            return null;
        }
        $width = str_starts_with($numbers[1], '0') ? \strlen($numbers[1]) : 0;

        return array_map(static fn (int $value): string => str_pad((string) $value, $width, '0', \STR_PAD_LEFT), range($first, $last, $step));
    }
}
