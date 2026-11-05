<?php

declare(strict_types=1);

namespace App\Mcp;

use App\GitHub\NewPullRequest;
use App\GitHub\PullRequestOpener;
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

        return $this->form($this->message($command, $assessment, $grants, $decisions, $plainWords), $decisions);
    }

    /**
     * The form about a pull request to open, asked every time: what will be created, exactly as gh is called, the
     * body indented so that nothing in it can pass for a line of the form.
     */
    public function pullRequest(NewPullRequest $pullRequest): ElicitRequest
    {
        $repository = $pullRequest->repository;
        $details = [
            'Repository: '.$repository->host.'/'.$repository->name,
            'Branch: '.$pullRequest->head->head().' → '.$pullRequest->base,
            'Title: '.$pullRequest->title,
            'Draft: '.($pullRequest->isDraft ? 'yes' : 'no'),
        ];
        $message = implode("\n\n", [
            'Asked every time: '.PullRequestOpener::REASON.'.',
            "Open this pull request?\n\n    ".implode("\n    ", $details),
            "Body:\n\n".$this->indented($pullRequest->body),
            'Airlock opens it with your GitHub CLI, outside the sandbox.',
        ]);

        return $this->form($message, Decision::offeredFor(Verdict::AskEveryTime));
    }

    /**
     * @param non-empty-list<Decision> $decisions
     */
    private function form(string $message, array $decisions): ElicitRequest
    {
        return new ElicitRequest(
            $message,
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

    private function indented(string $text): string
    {
        if ('' === trim($text)) {
            return '    (empty)';
        }

        return '    '.implode("\n    ", explode("\n", rtrim(str_replace("\r\n", "\n", $text))));
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
