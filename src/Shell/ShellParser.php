<?php

declare(strict_types=1);

namespace App\Shell;

/**
 * Reads a command line the way bash does, far enough to know every program it runs.
 *
 * Simple commands, pipes, `&&`, `||`, `;`, `&`, subshells and groups, redirections, quotes, escapes and comments are
 * read. What decides only when the line runs which program runs (a substitution, a here-document, a loop, a program
 * named by a variable or a glob) is not interpreted: it makes the line opaque.
 */
final class ShellParser
{
    private const array COMPOUND_KEYWORDS = [
        'if', 'then', 'else', 'elif', 'fi', 'for', 'while', 'until', 'do', 'done', 'case', 'esac', 'select',
        'function', 'coproc',
    ];

    /** Words that group or negate commands without changing which program runs. */
    private const array GROUPING_WORDS = ['{', '}', '!'];

    public function parse(string $line): CommandLine
    {
        $lexer = new Lexer($line);
        $tokens = $lexer->tokens();
        $opacity = $lexer->opacity();

        $commands = [];
        /** @var list<Token> $words */
        $words = [];
        $redirections = [];
        $pendingOperator = null;
        $readsPipe = false;

        foreach ($tokens as $token) {
            if (TokenType::Word === $token->type && null !== $pendingOperator) {
                $redirections[] = new Redirection($pendingOperator, $token->text);
                $pendingOperator = null;
                continue;
            }
            if (TokenType::Word === $token->type && [] === $words && [] === $redirections && $this->isGrouping($token)) {
                continue;
            }
            if (TokenType::Word === $token->type) {
                $words[] = $token;
                continue;
            }
            if (null !== $pendingOperator) {
                $opacity ??= Opacity::MissingRedirectionTarget;
                $pendingOperator = null;
            }
            if (TokenType::Redirection === $token->type) {
                $pendingOperator = $token->text;
                continue;
            }

            $commands[] = $this->command($words, $redirections, $readsPipe, TokenType::Separator === $token->type && '&' === $token->text);
            $words = [];
            $redirections = [];
            $readsPipe = TokenType::Pipe === $token->type;
        }

        if (null !== $pendingOperator) {
            $opacity ??= Opacity::MissingRedirectionTarget;
        }
        $commands[] = $this->command($words, $redirections, $readsPipe, runsInBackground: false);

        $commands = array_values(array_filter($commands, static fn (SimpleCommand $command): bool => [] !== $command->words || [] !== $command->redirections));
        $opacity ??= $this->opacityOfPrograms($tokens);

        return new CommandLine($commands, $opacity);
    }

    /**
     * @param list<Token>       $words
     * @param list<Redirection> $redirections
     */
    private function command(array $words, array $redirections, bool $readsPipe, bool $runsInBackground): SimpleCommand
    {
        $texts = array_map(static fn (Token $word): string => $word->text, $words);
        $assignments = 0;
        while ($assignments < \count($words) && $words[$assignments]->isAssignment()) {
            ++$assignments;
        }

        return new SimpleCommand($texts, $redirections, $readsPipe, $assignments, $runsInBackground);
    }

    /**
     * Why the program of a command is not known before it runs, for the first command where it is not.
     *
     * @param list<Token> $tokens
     */
    private function opacityOfPrograms(array $tokens): ?Opacity
    {
        foreach ($this->programs($tokens) as $program) {
            if (\in_array($program->text, self::COMPOUND_KEYWORDS, true)) {
                return Opacity::CompoundCommand;
            }
            if (!$program->isLiteral) {
                return Opacity::ComputedProgram;
            }
        }

        return null;
    }

    /**
     * The word naming the program of each simple command: past grouping words and variable assignments.
     *
     * @param list<Token> $tokens
     *
     * @return list<Token>
     */
    private function programs(array $tokens): array
    {
        $programs = [];
        $isCommandStart = true;
        $isOpening = true;
        $isRedirectionTarget = false;
        foreach ($tokens as $token) {
            if (TokenType::Word !== $token->type) {
                $isRedirectionTarget = TokenType::Redirection === $token->type;
                $isCommandStart = $isCommandStart || !$isRedirectionTarget;
                $isOpening = !$isRedirectionTarget;
                continue;
            }
            if ($isRedirectionTarget) {
                $isRedirectionTarget = false;
                continue;
            }
            if ($isOpening && $this->isGrouping($token)) {
                continue;
            }
            $isOpening = false;
            if (!$isCommandStart || $token->isAssignment()) {
                continue;
            }
            $programs[] = $token;
            $isCommandStart = false;
        }

        return $programs;
    }

    /**
     * Whether a word groups or negates the command it opens. bash reads `{`, `}` and `!` so only unquoted and before any
     * other word or redirection of the command: anywhere else they are words like the others.
     */
    private function isGrouping(Token $word): bool
    {
        return !$word->isQuoted() && \in_array($word->text, self::GROUPING_WORDS, true);
    }
}
