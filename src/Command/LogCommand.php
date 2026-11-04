<?php

declare(strict_types=1);

namespace App\Command;

use App\Audit\AuditLog;
use App\Audit\Outcome;
use App\Configuration\ConfigDirectory;
use App\Execution\ProjectDirectory;
use App\Output\OutputVerdict;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The last decisions Airlock made in the project, in plain words: what the audit log holds, readable at a glance.
 */
#[AsCommand(name: 'log', description: 'Show the last decisions Airlock made about commands in this project')]
final class LogCommand extends Command
{
    private const int COMMAND_WIDTH = 60;

    public function __construct(
        private readonly AuditLog $auditLog,
        private readonly ProjectDirectory $projectDirectory,
        private readonly ConfigDirectory $configDirectory,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('last', null, InputOption::VALUE_REQUIRED, 'How many decisions to show, the newest ones', '20');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $last = $input->getOption('last');
        if (!\is_string($last) || 1 !== preg_match('/^[1-9][0-9]{0,5}$/', $last)) {
            $io->error('--last takes a number above 0.');

            return Command::FAILURE;
        }

        $lines = $this->auditLog->lastEntriesFor($this->projectDirectory->path, (int) $last);
        if ([] === $lines) {
            $io->success(\sprintf('Airlock has made no decision in this project yet (%s).', $this->projectDirectory->path));

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($lines as $line) {
            $rows[] = $this->row($line);
        }
        $io->title(\sprintf('Last decisions in %s', $this->projectDirectory->path));
        $io->table(['When', 'Command', 'Decision', 'Output'], $rows);
        $io->text(\sprintf('Every detail of each, one JSON object per line: %s/audit.jsonl', $this->configDirectory->path));

        return Command::SUCCESS;
    }

    /**
     * @return array{string, string, string, string}
     */
    private function row(string $line): array
    {
        $entry = json_decode($line, true);
        if (!\is_array($entry)) {
            return ['?', '?', '?', '?'];
        }

        return [
            $this->when($entry['at'] ?? null),
            TerminalText::shortened(\is_string($entry['command'] ?? null) ? $entry['command'] : '?', self::COMMAND_WIDTH),
            $this->decision($entry['outcome'] ?? null),
            $this->output($entry['output_check'] ?? null, $entry['output_check_reason'] ?? null),
        ];
    }

    private function when(mixed $at): string
    {
        if (!\is_string($at)) {
            return '?';
        }
        $time = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $at);

        return false === $time ? TerminalText::of($at) : $time->format('Y-m-d H:i');
    }

    private function decision(mixed $outcome): string
    {
        $known = \is_string($outcome) ? Outcome::tryFrom($outcome) : null;

        return match ($known) {
            Outcome::RefusedByPolicy => 'refused by the policy',
            Outcome::RefusedByUser => 'refused by you',
            Outcome::RanOnce => 'allowed once',
            Outcome::RanAndGrantedForSession => 'allowed for the session',
            Outcome::RanAndGrantedForProject => 'allowed always',
            Outcome::RanUnderGrant => 'ran under a grant',
            Outcome::Failed => 'failed',
            null => '?',
        };
    }

    private function output(mixed $check, mixed $reason): string
    {
        $verdict = \is_string($check) ? OutputVerdict::tryFrom($check) : null;

        return match ($verdict) {
            OutputVerdict::Injection => 'injection flagged',
            OutputVerdict::Clean => 'clean',
            OutputVerdict::NotChecked => \sprintf('not checked (%s)', \is_string($reason) ? str_replace('_', ' ', $reason) : 'unknown'),
            null => '',
        };
    }
}
