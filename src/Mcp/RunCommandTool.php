<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Audit\Outcome;
use App\Execution\CommandResult;
use App\Execution\CommandRunner;
use App\Execution\ProjectDirectory;
use App\Execution\RegistriesProxyUnavailableException;
use App\Grant\GrantRules;
use App\Grant\Grants;
use App\Grant\InvalidGrantsException;
use App\Grant\ProjectGrants;
use App\Grant\SessionGrants;
use App\Output\InjectionClassifier;
use App\Output\OutputCheck;
use App\Policy\Assessment;
use App\Policy\CommandPolicy;
use App\Policy\InvalidPolicyException;
use App\Policy\PolicyFiles;
use App\Policy\Verdict;
use App\Risk\RiskExplainer;
use App\Sandbox\NoSandbox;
use App\Sandbox\ReplaceableGitLinkException;
use App\Sandbox\SandboxDetector;
use App\Shell\CommandLine;
use App\Shell\ShellParser;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server\RequestContext;
use Psr\Clock\ClockInterface;

/**
 * Runs a command once the user has allowed it, or a grant covers it, and only in the sandbox.
 *
 * Written for the 2026-07-28 protocol: the first call returns the form as an input-required result, and the client's
 * retry carries the answer. The SDK serves the same tool to 2025-11-25 clients through elicitation/create.
 */
#[McpTool(
    name: 'run_command',
    description: 'Run a shell command (bash) in the project, in a sandbox, once the user allows it. Returns its exit code and output.',
)]
final readonly class RunCommandTool
{
    public function __construct(
        private ShellParser $parser,
        private PolicyFiles $policyFiles,
        private CommandPolicy $policy,
        private Grants $grants,
        private GrantRules $grantRules,
        private SessionGrants $sessionGrants,
        private ProjectGrants $projectGrants,
        private ApprovalForm $approvalForm,
        private RiskExplainer $riskExplainer,
        private Approvals $approvals,
        private CommandRunner $runner,
        private SandboxDetector $sandboxDetector,
        private InjectionClassifier $injectionClassifier,
        private OutputReport $outputReport,
        private AuditLog $auditLog,
        private ProjectDirectory $projectDirectory,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param string $command the command line, as typed in bash
     */
    public function __invoke(string $command, RequestContext $context): CallToolResult|InputRequiredResult
    {
        if ('' === trim($command)) {
            return CallToolResult::error([new TextContent('The command is empty: there is nothing to run.')]);
        }

        $line = $this->parser->parse($command);
        try {
            $assessment = $this->policy->assess($line, $this->policyFiles->refusals());
            // A grant was given for a command that stays in the sandbox: without one, every command is asked.
            $isCovered = Verdict::Plain === $assessment->verdict && !$this->sandboxDetector->detect() instanceof NoSandbox && $this->grants->covers($line);
            $miss = $isCovered || Verdict::Plain !== $assessment->verdict ? null : $this->grants->miss($line);
        } catch (InvalidPolicyException|InvalidGrantsException $e) {
            $this->audit($command, new Assessment(Verdict::Refused), Outcome::Failed, detail: $e->getMessage());

            return CallToolResult::error([new TextContent(\sprintf("Nothing runs until this is fixed: %s\n%s did not run.", $e->getMessage(), $command))]);
        }

        if (Verdict::Refused === $assessment->verdict) {
            $this->audit($command, $assessment, Outcome::RefusedByPolicy);

            return CallToolResult::error([new TextContent(\sprintf(
                "Refused by Airlock's policy, without asking the user: %s did not run.\n%s",
                $command,
                implode("\n", $assessment->reasons),
            ))]);
        }

        if ($isCovered) {
            return $this->run($command, $assessment, Outcome::RanUnderGrant, $this->runner->run(...));
        }

        $decision = $this->approvals->answer($context, $command, $assessment->verdict);
        if (!$decision instanceof Decision) {
            $plainWords = Verdict::AskEveryTime === $assessment->verdict ? $this->riskExplainer->explain($command) : null;

            return $this->approvals->ask($context, $command, $this->approvalForm->request($command, $assessment, $this->grantRules->for($line), $plainWords, $miss));
        }

        return $this->decide($decision, $command, $line, $assessment);
    }

    private function decide(Decision $decision, string $command, CommandLine $line, Assessment $assessment): CallToolResult
    {
        if (Decision::Refuse === $decision) {
            $this->audit($command, $assessment, Outcome::RefusedByUser);

            return CallToolResult::error([new TextContent(\sprintf('Refused by the user: %s did not run.', $command))]);
        }

        $outcome = match ($decision) {
            Decision::Session => Outcome::RanAndGrantedForSession,
            Decision::Always => Outcome::RanAndGrantedForProject,
            Decision::Once => Outcome::RanOnce,
        };
        if (Decision::Session === $decision) {
            $this->sessionGrants->add($this->grantRules->for($line));
        }
        if (Decision::Always === $decision) {
            $this->projectGrants->add($this->grantRules->for($line));
        }

        // The user's SSH agent goes only to a command the user has just allowed, never to one a grant covers.
        $run = $assessment->needsSshAgent ? $this->runner->runWithSshAgent(...) : $this->runner->run(...);

        return $this->run($command, $assessment, $outcome, $run);
    }

    /**
     * @param \Closure(string): CommandResult $run
     */
    private function run(string $command, Assessment $assessment, Outcome $outcome, \Closure $run): CallToolResult
    {
        try {
            $result = $run($command);
        } catch (ReplaceableGitLinkException|RegistriesProxyUnavailableException $e) {
            $this->audit($command, $assessment, Outcome::Failed, detail: $e->getMessage());

            return CallToolResult::error([new TextContent(\sprintf("Nothing runs until this is fixed: %s\n%s did not run.", $e->getMessage(), $command))]);
        }
        $check = $this->injectionClassifier->check($result->output);
        $this->audit($command, $assessment, $outcome, $result, check: $check);

        return $this->outputReport->result($result, $check);
    }

    private function audit(string $command, Assessment $assessment, Outcome $outcome, ?CommandResult $result = null, ?string $detail = null, ?OutputCheck $check = null): void
    {
        $this->auditLog->append(new AuditEntry(
            $this->clock->now(),
            $this->projectDirectory->path,
            $command,
            $assessment->verdict,
            $outcome,
            $assessment->reasons,
            $result?->exitCode,
            $result instanceof CommandResult && $result->hasTimedOut,
            $detail,
            $check,
            $result?->maskedSecrets,
            $result->blockedConnections ?? [],
        ));
    }
}
