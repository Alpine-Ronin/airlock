<?php

declare(strict_types=1);

namespace App\Command;

use App\Execution\ProjectDirectory;
use App\Grant\Grant;
use App\Grant\InvalidGrantsException;
use App\Grant\ProjectGrants;
use App\Grant\Source;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The commands the user allowed always in the project, and a way to take one back without editing a file. Grants for
 * a session live in the server that was given them, and end with it.
 */
#[AsCommand(name: 'grants', description: 'List the commands allowed always in this project, or revoke some')]
final class GrantsCommand extends Command
{
    public function __construct(
        private readonly ProjectGrants $grants,
        private readonly ProjectDirectory $projectDirectory,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('revoke', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'The number of a grant to revoke, as listed; repeat it for several');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $grants = $this->grants->all();
        } catch (InvalidGrantsException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $numbers = $input->getOption('revoke');
        if (!\is_array($numbers) || [] === $numbers) {
            return $this->list($io, $grants);
        }

        return $this->revoke($io, $grants, $numbers);
    }

    /**
     * @param list<Grant> $grants
     */
    private function list(SymfonyStyle $io, array $grants): int
    {
        if ([] === $grants) {
            $io->success(\sprintf('No command is allowed always in this project (%s).', $this->projectDirectory->path));

            return Command::SUCCESS;
        }

        $io->title(\sprintf('Allowed always in %s', $this->projectDirectory->path));
        $numbered = [];
        foreach ($grants as $index => $grant) {
            $numbered[$index + 1] = $grant;
        }
        $this->show($io, $numbered);
        $io->text([
            'A command ending in … also allows longer ones starting with it.',
            'Revoke one with: airlock grants --revoke <number>',
        ]);

        return Command::SUCCESS;
    }

    /**
     * @param list<Grant>  $grants
     * @param array<mixed> $numbers
     */
    private function revoke(SymfonyStyle $io, array $grants, array $numbers): int
    {
        $chosen = [];
        foreach ($numbers as $given) {
            $number = $this->number($given);
            $grant = null === $number ? null : ($grants[$number - 1] ?? null);
            if (null === $number || !$grant instanceof Grant) {
                $io->error(\sprintf('No grant is numbered %s: run airlock grants to see them. Nothing was revoked.', \is_string($given) ? $given : '?'));

                return Command::FAILURE;
            }
            $chosen[$number] = $grant;
        }

        $this->show($io, $chosen);
        if (!$io->confirm('Revoke these grants?', true)) {
            $io->text('Nothing was revoked.');

            return Command::SUCCESS;
        }

        $this->grants->revoke(array_values($chosen));
        $io->success('Revoked: Airlock asks about these commands again.');

        return Command::SUCCESS;
    }

    /**
     * @return positive-int|null null when what was given is not a grant's number
     */
    private function number(mixed $given): ?int
    {
        if (!\is_string($given) || 1 !== preg_match('/^[1-9][0-9]{0,8}$/', $given)) {
            return null;
        }

        return max(1, (int) $given);
    }

    /**
     * @param array<positive-int, Grant> $grants by their number
     */
    private function show(SymfonyStyle $io, array $grants): void
    {
        $rows = [];
        foreach ($grants as $number => $grant) {
            $sources = implode(', ', array_map(static fn (Source $source): string => $source->path, $grant->sources));
            $rows[] = [(string) $number, TerminalText::of($grant->describe()), TerminalText::of($sources)];
        }
        $io->table(['#', 'Command', 'While unchanged'], $rows);
    }
}
