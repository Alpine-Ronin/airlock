<?php

declare(strict_types=1);

namespace App\Shell;

final readonly class Token
{
    /**
     * @param bool     $isLiteral  whether the word means the same whatever runs it: no variable, no substitution, no glob or brace expansion
     * @param int|null $quotedFrom where in the text the first quoted or escaped character is, or null when nothing is quoted
     */
    public function __construct(
        public TokenType $type,
        public string $text,
        public bool $isLiteral = true,
        public ?int $quotedFrom = null,
    ) {
    }

    /**
     * Whether part of the word is quoted or escaped, which keeps it from being a reserved word such as `{`.
     */
    public function isQuoted(): bool
    {
        return null !== $this->quotedFrom;
    }

    /**
     * Whether the word sets a variable for the command (`A=1`): bash reads it so only when the name and its `=` are
     * unquoted. `''A=1` or `a"b"=1` is a word like any other, and names the program when it comes first.
     */
    public function isAssignment(): bool
    {
        if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*\+?=/', $this->text, $match)) {
            return false;
        }

        return null === $this->quotedFrom || \strlen($match[0]) <= $this->quotedFrom;
    }
}
