<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Grant\Grant;
use App\Policy\Assessment;
use App\Policy\Verdict;
use App\Sandbox\SandboxDetector;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\EnumSchemaDefinition;
use Mcp\Schema\Request\ElicitRequest;

/**
 * The form a user answers about one command: why it is asked first, since clients fold long messages after a few
 * lines, and a model's reading of it in plain words under the rule, never instead of it; then the command, where it
 * runs, and what a grant would cover.
 */
final readonly class ApprovalForm
{
    public const string DECISION = 'decision';

    public function __construct(
        private SandboxDetector $sandboxDetector,
    ) {
    }

    /**
     * @param list<Grant> $grants     what "this session" and "always" would allow from now on
     * @param string|null $plainWords a model's one-line reading of the command, when there is one
     */
    public function request(string $command, Assessment $assessment, array $grants, ?string $plainWords = null): ElicitRequest
    {
        $decisions = Decision::offeredFor($assessment->verdict);

        return new ElicitRequest(
            $this->message($command, $assessment, $grants, $decisions, $plainWords),
            new ElicitationSchema(
                [self::DECISION => new EnumSchemaDefinition(
                    title: 'Decision',
                    enum: array_map(static fn (Decision $decision): string => $decision->value, $decisions),
                    enumNames: array_map(static fn (Decision $decision): string => $decision->label(), $decisions),
                )],
                [self::DECISION],
            ),
        );
    }

    /**
     * @param list<Grant>    $grants
     * @param list<Decision> $decisions
     */
    private function message(string $command, Assessment $assessment, array $grants, array $decisions, ?string $plainWords): string
    {
        $paragraphs = [
            $this->whyAsked($assessment, $plainWords),
            "Run this command?\n\n    ".$command,
            $this->sandboxDetector->detect()->describe(),
        ];
        if ($assessment->needsSshAgent) {
            $paragraphs[] = 'If you allow it, it can use your SSH agent and read your known hosts: it talks to another machine.';
        }
        if (\in_array(Decision::Always, $decisions, true) && [] !== $grants) {
            $paragraphs[] = 'For this session or always, it allows: '.implode(', ', array_map(static fn (Grant $grant): string => '`'.$grant->describe().'`', $grants));
        }

        return implode("\n\n", $paragraphs);
    }

    private function whyAsked(Assessment $assessment, ?string $plainWords): string
    {
        if (Verdict::AskEveryTime === $assessment->verdict) {
            $rule = 'Asked every time: '.implode('; ', $assessment->reasons).'.';

            return null === $plainWords ? $rule : $rule."\nIn plain words (from a model, may be wrong): ".$plainWords;
        }

        return 'Not covered by any grant yet.';
    }
}
