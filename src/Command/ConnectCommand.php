<?php

declare(strict_types=1);

namespace App\Command;

use App\Connect\AirlockExecutable;
use App\Connect\Client;
use App\Connect\ClientConfigFiles;
use App\Connect\ClientConnector;
use App\Connect\ClientConnectors;
use App\Connect\ConfigChange;
use App\Connect\ConfigChangedException;
use App\Connect\ConnectionContext;
use App\Connect\Direction;
use App\Connect\FileEdit;
use App\Connect\PlannedFile;
use App\Connect\Scope;
use App\Connect\UnsupportedConfigException;
use App\Execution\ProjectDirectory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Connects Airlock to an MCP client from its configuration files: Airlock's server entry, and the client's own shell
 * tool turned off where a file can do it, so that commands have no way around Airlock.
 */
#[AsCommand(name: 'connect', description: 'Connect Airlock to an MCP client, and turn off the client\'s own shell tool where a file can')]
final class ConnectCommand extends Command
{
    public function __construct(
        private readonly ClientConnectors $connectors,
        private readonly ClientConfigFiles $files,
        private readonly AirlockExecutable $executable,
        private readonly ProjectDirectory $projectDirectory,
        #[Autowire(env: 'HOME')]
        private readonly string $home,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('client', InputArgument::OPTIONAL, \sprintf('One of %s; without it, the entry for any other client', implode(', ', Client::values())))
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'project (this project) or user (every project), where the client offers both')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Say whether Airlock is connected, change nothing')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Take back what connecting added')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change, write nothing');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('client');
        if (!\is_string($name) || '' === $name) {
            return $this->showOtherClients($io);
        }

        $client = Client::tryFrom($name);
        if (!$client instanceof Client) {
            $io->error(\sprintf('Airlock does not know the client "%s". It connects %s; for any other, run this command without a client.', $name, implode(', ', Client::values())));

            return Command::FAILURE;
        }
        $connector = $this->connectors->for($client);
        $scope = $this->scope($input, $connector);
        if (!$scope instanceof Scope) {
            $io->error(\sprintf('%s connects at the %s level only.', $client->label(), implode(' or ', array_map(static fn (Scope $scope): string => $scope->value, $connector->scopes()))));

            return Command::FAILURE;
        }

        $direction = true === $input->getOption('remove') ? Direction::Disconnect : Direction::Connect;
        $planned = $this->plan($io, $connector->edits($scope, $this->context()), $direction);
        if (null === $planned) {
            return Command::FAILURE;
        }
        if (true === $input->getOption('check')) {
            return $this->check($io, $client, $connector->notes($scope), $planned, $direction);
        }

        return $this->apply($io, $input, $client, $connector->notes($scope), $planned, $direction);
    }

    /**
     * @param non-empty-list<FileEdit> $edits
     *
     * @return list<PlannedFile>|null null when a file cannot be edited safely
     */
    private function plan(SymfonyStyle $io, array $edits, Direction $direction): ?array
    {
        $planned = [];
        foreach ($edits as $edit) {
            try {
                $planned[] = $this->files->plan($edit, $direction);
            } catch (UnsupportedConfigException $e) {
                $io->error([
                    \sprintf('Airlock cannot edit %s safely: %s. Nothing was written.', $edit->path, $e->getMessage()),
                    \sprintf('Make the change by hand (%s), then run this command again with --check.', implode('; ', array_map(static fn (ConfigChange $change): string => $change->describe($direction), $edit->changes))),
                ]);

                return null;
            }
        }

        return $planned;
    }

    /**
     * @param list<string>      $notes
     * @param list<PlannedFile> $planned
     */
    private function check(SymfonyStyle $io, Client $client, array $notes, array $planned, Direction $direction): int
    {
        $pending = array_values(array_filter($planned, static fn (PlannedFile $file): bool => $file->isChanged()));
        if ([] !== $pending) {
            $this->show($io, $pending);
            $io->error(\sprintf('Airlock is not %s %s: the changes above are missing.', $this->state($direction), $client->label()));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Airlock is %s %s.', $this->state($direction), $client->label()));
        if (Direction::Connect === $direction && [] !== $notes) {
            $io->note($notes);
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<string>      $notes
     * @param list<PlannedFile> $planned
     */
    private function apply(SymfonyStyle $io, InputInterface $input, Client $client, array $notes, array $planned, Direction $direction): int
    {
        $pending = array_values(array_filter($planned, static fn (PlannedFile $file): bool => $file->isChanged()));
        if ([] === $pending) {
            if (Direction::Disconnect === $direction && true !== $input->getOption('dry-run') && !$this->wroteAll($io, $planned, $direction)) {
                return Command::FAILURE;
            }
            $io->success(\sprintf('Airlock is already %s %s: nothing to change.', $this->state($direction), $client->label()));

            return Command::SUCCESS;
        }

        $this->show($io, $pending);
        if (true === $input->getOption('dry-run')) {
            $io->note('Dry run: nothing was written.');

            return Command::SUCCESS;
        }
        if (!$io->confirm('Write these changes?', true)) {
            $io->warning('Nothing was written.');

            return Command::FAILURE;
        }

        if (!$this->wroteAll($io, $planned, $direction)) {
            return Command::FAILURE;
        }
        $io->success(\sprintf('Airlock is %s %s. Restart %s so that it reads its new configuration.', $this->state($direction), $client->label(), $client->label()));
        if (Direction::Connect === $direction && [] !== $notes) {
            $io->note($notes);
        }
        $this->warnAboutWhatWasLeft($io, $planned);

        return Command::SUCCESS;
    }

    /**
     * @param list<PlannedFile> $planned
     */
    private function wroteAll(SymfonyStyle $io, array $planned, Direction $direction): bool
    {
        try {
            $this->files->writeAll($planned, $direction);
        } catch (ConfigChangedException $e) {
            $io->error(\sprintf('%s: nothing was written. Run this command again to see the changes on the file as it is now.', $e->getMessage()));

            return false;
        }

        return true;
    }

    /**
     * @param list<PlannedFile> $planned
     */
    private function warnAboutWhatWasLeft(SymfonyStyle $io, array $planned): void
    {
        foreach ($planned as $file) {
            if ([] !== $file->left) {
                $io->warning(\sprintf('Airlock has no record of %s before it connected, so it left %s as they are: change them by hand if they were not yours.', $file->path, implode(', ', $file->left)));
            }
        }
    }

    /**
     * @param list<PlannedFile> $files
     */
    private function show(SymfonyStyle $io, array $files): void
    {
        foreach ($files as $file) {
            $io->text($file->path.$this->fileLabel($file));
            $io->listing($file->changes);
        }
    }

    private function fileLabel(PlannedFile $file): string
    {
        if ($file->isRemoved()) {
            return ' (removed)';
        }

        return $file->exists ? '' : ' (new file)';
    }

    private function showOtherClients(SymfonyStyle $io): int
    {
        $server = $this->executable->serverCommand();
        $entry = ['mcpServers' => ['airlock' => [
            'command' => $server->command,
            'args' => $server->arguments,
            'env' => ['AIRLOCK_PROJECT_DIR' => $this->projectDirectory->path],
        ]]];

        $io->text([
            \sprintf('Airlock connects these clients itself: %s, with <info>airlock connect <client></info>.', implode(', ', Client::values())),
            'Any other client needs MCP elicitation, or Airlock cannot ask before a command runs. Add this entry to its MCP settings, then turn off its own shell tool:',
            '',
        ]);
        $io->writeln(json_encode($entry, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }

    private function scope(InputInterface $input, ClientConnector $connector): ?Scope
    {
        $given = $input->getOption('scope');
        if (!\is_string($given)) {
            return $connector->scopes()[0];
        }
        $scope = Scope::tryFrom($given);

        return \in_array($scope, $connector->scopes(), true) ? $scope : null;
    }

    private function context(): ConnectionContext
    {
        return new ConnectionContext($this->projectDirectory->path, $this->home, $this->executable->serverCommand());
    }

    private function state(Direction $direction): string
    {
        return match ($direction) {
            Direction::Connect => 'connected to',
            Direction::Disconnect => 'disconnected from',
        };
    }
}
