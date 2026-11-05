<?php

declare(strict_types=1);

namespace App\Shell;

/**
 * Splits one command line into words and operators, the way bash does before it expands anything.
 *
 * Quotes, escapes and comments are resolved; what only the running shell can resolve (a substitution, a
 * here-document) is kept as text and recorded as the line's opacity.
 *
 * @internal used by {@see ShellParser}, one instance per line
 */
final class Lexer
{
    /** Characters that, after a `$`, start an expansion rather than stand for a dollar sign: `$'...'` and `$"..."` too. */
    private const string EXPANSION_START = '/^[A-Za-z0-9_{@*#?$!\'"-]$/';

    /** Characters that, unquoted, make bash expand the word: globs, and braces (`{rm,-rf,x}` is `rm -rf x`). */
    private const array EXPANDING = ['*', '?', '[', '{'];

    private const array ESCAPABLE_IN_DOUBLE_QUOTES = ['"', '\\', '$', '`', "\n"];

    private int $position = 0;

    private string $word = '';

    private bool $isInWord = false;

    private bool $isWordLiteral = true;

    private ?int $wordQuotedFrom = null;

    /** @var list<Token> */
    private array $tokens = [];

    private ?Opacity $opacity = null;

    public function __construct(
        private readonly string $line,
    ) {
    }

    /**
     * @return list<Token>
     */
    public function tokens(): array
    {
        $length = \strlen($this->line);
        while ($this->position < $length) {
            $this->readAt($this->line[$this->position], $this->peek(1));
            ++$this->position;
        }
        $this->endWord();

        return $this->tokens;
    }

    public function opacity(): ?Opacity
    {
        return $this->opacity;
    }

    private function readAt(string $character, string $next): void
    {
        match (true) {
            '\\' === $character => $this->readEscape($next),
            "'" === $character => $this->readSingleQuoted(),
            '"' === $character => $this->readDoubleQuoted(),
            '`' === $character => $this->readBacktick(),
            '$' === $character => $this->readDollar($next),
            '#' === $character && !$this->isInWord => $this->skipComment(),
            ' ' === $character, "\t" === $character => $this->endWord(),
            \in_array($character, ["\n", ';', '(', ')'], true) => $this->readSeparator($character, $next),
            '|' === $character => $this->readBar($next),
            '&' === $character && '>' !== $next => $this->readAmpersand($next),
            ('<' === $character || '>' === $character) && '(' === $next => $this->readProcessSubstitution($character),
            \in_array($character, ['<', '>', '&'], true) => $this->readRedirection(),
            \in_array($character, self::EXPANDING, true) => $this->append($character, isLiteral: false),
            default => $this->append($character),
        };
    }

    private function readEscape(string $next): void
    {
        ++$this->position;
        if ("\n" === $next) {
            return;
        }
        $this->wordQuotedFrom ??= \strlen($this->word);
        // A backslash that ends the line escapes nothing: bash keeps it.
        $this->append('' === $next ? '\\' : $next);
    }

    private function readSingleQuoted(): void
    {
        $this->isInWord = true;
        $this->wordQuotedFrom ??= \strlen($this->word);
        $end = strpos($this->line, "'", $this->position + 1);
        if (false === $end) {
            $this->markOpaque(Opacity::UnclosedQuote);
            $end = \strlen($this->line);
        }
        $this->word .= substr($this->line, $this->position + 1, $end - $this->position - 1);
        $this->position = $end;
    }

    private function readDoubleQuoted(): void
    {
        $this->isInWord = true;
        $this->wordQuotedFrom ??= \strlen($this->word);
        $length = \strlen($this->line);
        for (++$this->position; $this->position < $length; ++$this->position) {
            $character = $this->line[$this->position];
            if ('"' === $character) {
                return;
            }
            $this->readInDoubleQuotes($character, $this->peek(1));
        }
        $this->markOpaque(Opacity::UnclosedQuote);
    }

    private function readInDoubleQuotes(string $character, string $next): void
    {
        if ('\\' === $character && \in_array($next, self::ESCAPABLE_IN_DOUBLE_QUOTES, true)) {
            ++$this->position;
            if ("\n" !== $next) {
                $this->word .= $next;
            }

            return;
        }
        if ('`' === $character) {
            $this->readBacktick();

            return;
        }
        if ('$' === $character) {
            $this->readDollar($next);

            return;
        }
        $this->word .= $character;
    }

    private function readBacktick(): void
    {
        $this->markOpaque(Opacity::CommandSubstitution);
        $this->append('`', isLiteral: false);
    }

    private function readDollar(string $next): void
    {
        if ('(' === $next) {
            $this->markOpaque(Opacity::CommandSubstitution);
        }
        $startsExpansion = '(' === $next || 1 === preg_match(self::EXPANSION_START, $next);
        $this->append('$', isLiteral: !$startsExpansion);
    }

    private function skipComment(): void
    {
        $end = strpos($this->line, "\n", $this->position);
        // Stop before the newline: it still ends the command the comment closes.
        $this->position = (false === $end ? \strlen($this->line) : $end) - 1;
    }

    private function readSeparator(string $character, string $next): void
    {
        $operator = $character;
        if (';' === $character && ';' === $next) {
            $operator = ';;';
            ++$this->position;
        }
        $this->emit(TokenType::Separator, $operator);
    }

    private function readBar(string $next): void
    {
        if ('|' === $next) {
            ++$this->position;
            $this->emit(TokenType::Separator, '||');

            return;
        }
        if ('&' === $next) {
            ++$this->position;
            $this->emit(TokenType::Pipe, '|&');

            return;
        }
        $this->emit(TokenType::Pipe, '|');
    }

    private function readAmpersand(string $next): void
    {
        if ('&' === $next) {
            ++$this->position;
            $this->emit(TokenType::Separator, '&&');

            return;
        }
        $this->emit(TokenType::Separator, '&');
    }

    private function readProcessSubstitution(string $character): void
    {
        $this->markOpaque(Opacity::ProcessSubstitution);
        $this->append($character.'(', isLiteral: false);
        ++$this->position;
    }

    private function readRedirection(): void
    {
        // Unquoted digits written right before the operator name a file descriptor (2>&1), not a word.
        if ($this->isInWord && $this->isWordLiteral && null === $this->wordQuotedFrom && ctype_digit($this->word)) {
            $this->word = '';
            $this->isInWord = false;
        }

        $operator = $this->line[$this->position];
        while (\strlen($operator) < 3 && \in_array($this->peek(1), ['<', '>', '&', '|', '-'], true) && $this->extendsOperator($operator.$this->peek(1))) {
            $operator .= $this->peek(1);
            ++$this->position;
        }

        if ('<<' === $operator || '<<-' === $operator) {
            $this->markOpaque(Opacity::HereDocument);
        }
        $this->emit(TokenType::Redirection, $operator);
    }

    private function extendsOperator(string $candidate): bool
    {
        return \in_array($candidate, ['>>', '>|', '>&', '<<', '<<-', '<<<', '<>', '<&', '&>', '&>>'], true);
    }

    private function append(string $text, bool $isLiteral = true): void
    {
        $this->word .= $text;
        $this->isInWord = true;
        $this->isWordLiteral = $this->isWordLiteral && $isLiteral;
    }

    private function emit(TokenType $type, string $operator): void
    {
        $this->endWord();
        $this->tokens[] = new Token($type, $operator);
    }

    private function endWord(): void
    {
        if (!$this->isInWord) {
            return;
        }
        $this->tokens[] = new Token(TokenType::Word, $this->word, $this->isWordLiteral, $this->wordQuotedFrom);
        $this->word = '';
        $this->isInWord = false;
        $this->isWordLiteral = true;
        $this->wordQuotedFrom = null;
    }

    private function markOpaque(Opacity $opacity): void
    {
        $this->opacity ??= $opacity;
    }

    private function peek(int $offset): string
    {
        return $this->line[$this->position + $offset] ?? '';
    }
}
