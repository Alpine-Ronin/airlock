<?php

declare(strict_types=1);

namespace App\Command;

use App\Doctor\Check;
use App\Doctor\Finding;
use App\Doctor\Health;
use App\Execution\ProjectDirectory;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Says whether Airlock can protect this project, and what to do when it cannot: it changes nothing and reaches no
 * network. It fails only on a problem, never on a warning.
 */
#[AsCommand(name: 'doctor', description: 'Check that Airlock can protect this project: the sandbox, the model checks, its files and the clients')]
final class DoctorCommand extends Command
{
    /**
     * @param iterable<Check> $checks
     */
    public function __construct(
        #[AutowireIterator(Check::class)]
        private readonly iterable $checks,
        private readonly ProjectDirectory $projectDirectory,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title(\sprintf('%s, in %s', $this->version(), OutputFormatter::escape($this->projectDirectory->path)));

        $findings = [];
        foreach ($this->checks as $check) {
            $finding = $check->run();
            $findings[] = $finding;
            $this->show($io, $finding);
        }
        $io->newLine();

        return $this->conclude($io, $findings);
    }

    private function show(SymfonyStyle $io, Finding $finding): void
    {
        $mark = match ($finding->health) {
            Health::Ok => '<info>✔</info>',
            Health::Warning => '<comment>!</comment>',
            Health::Problem => '<error>✘</error>',
        };
        $io->writeln(\sprintf(' %s <options=bold>%s</>: %s', $mark, $finding->topic, OutputFormatter::escape($finding->text)));
    }

    /**
     * @param list<Finding> $findings
     */
    private function conclude(SymfonyStyle $io, array $findings): int
    {
        $problems = $this->count($findings, Health::Problem);
        if ($problems > 0) {
            $io->error(\sprintf('%d %s to fix, above.', $problems, 1 === $problems ? 'problem' : 'problems'));

            return Command::FAILURE;
        }

        $warnings = $this->count($findings, Health::Warning);
        if ($warnings > 0) {
            $io->warning(\sprintf('Airlock works, with %d %s above.', $warnings, 1 === $warnings ? 'warning' : 'warnings'));

            return Command::SUCCESS;
        }

        $io->success('Airlock is ready to protect this project.');

        return Command::SUCCESS;
    }

    /**
     * @param list<Finding> $findings
     */
    private function count(array $findings, Health $health): int
    {
        return \count(array_filter($findings, static fn (Finding $finding): bool => $finding->health === $health));
    }

    private function version(): string
    {
        $application = $this->getApplication();

        return $application instanceof Application ? \sprintf('Airlock %s', $application->getVersion()) : 'Airlock';
    }
}
