<?php

declare(strict_types=1);

namespace App\Command;

use App\Connect\AirlockExecutable;
use App\Update\Attestation;
use App\Update\BinaryUpdater;
use App\Update\UpdateFailedException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'update', description: 'Replace this Airlock binary with the latest release, or a given version')]
final class UpdateCommand extends Command
{
    public function __construct(
        private readonly BinaryUpdater $updater,
        private readonly AirlockExecutable $executable,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('version', InputArgument::OPTIONAL, 'The version to install, such as 1.0.0 (default: the latest release)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $version = $input->getArgument('version');
        if (null !== $version && (!\is_string($version) || 1 !== preg_match('/^v?[0-9]+\.[0-9]+\.[0-9]+$/', $version))) {
            $io->error('The version is three numbers, such as 1.0.0.');

            return Command::FAILURE;
        }
        $binaryPath = $this->executable->binaryPath();
        if (null === $binaryPath) {
            $io->error('This Airlock runs from a checkout, with PHP: update it with git pull, then composer install. With Docker, build the image again. airlock update replaces a binary installed by install.sh.');

            return Command::FAILURE;
        }

        try {
            $update = null === $version
                ? $this->updater->toLatest($binaryPath, $this->installedVersion())
                : $this->updater->toVersion($binaryPath, $this->installedVersion(), 'v'.ltrim($version, 'v'));
        } catch (UpdateFailedException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($update->isAlreadyInstalled()) {
            $io->success(\sprintf('Airlock %s is installed already: %s.', $update->toVersion, $binaryPath));

            return Command::SUCCESS;
        }
        if (Attestation::NotChecked === $update->attestation) {
            $io->note('Not checked where it was built: with the GitHub CLI installed and logged in, airlock update checks it.');
        }
        $io->success(\sprintf('Updated %s from Airlock %s to %s. Restart your MCP client to use it.', $binaryPath, $update->fromVersion, $update->toVersion));

        return Command::SUCCESS;
    }

    private function installedVersion(): string
    {
        $application = $this->getApplication();

        return $application instanceof Application ? $application->getVersion() : '';
    }
}
