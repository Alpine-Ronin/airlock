<?php

declare(strict_types=1);

namespace App\Command;

use App\Network\AllowedHosts;
use App\Network\JsonLinesDecisionLog;
use App\Network\ListenAddress;
use App\Network\NetworkProxy;
use App\Network\NetworkStreamException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The proxy to the package registries, served until it is stopped. Airlock starts it for a sandboxed command and
 * stops it after: it prints where it listens, then one JSON line per decision goes to the log file.
 */
#[AsCommand(name: 'network-proxy', description: 'Serve the proxy that lets a sandboxed command reach the package registries only, until stopped')]
final class NetworkProxyCommand extends Command implements SignalableCommandInterface
{
    private const float TICK_SECONDS = 0.5;

    private bool $isStopping = false;

    public function __construct(
        private readonly NetworkProxy $networkProxy,
    ) {
        parent::__construct();
    }

    /**
     * @return list<int>
     */
    #[\Override]
    public function getSubscribedSignals(): array
    {
        return [\SIGTERM, \SIGINT];
    }

    #[\Override]
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->isStopping = true;

        return false;
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('listen', null, InputOption::VALUE_REQUIRED, 'unix:///absolute/path, or 127.0.0.1:<port> (0 for any free port)')
            ->addOption('log', null, InputOption::VALUE_REQUIRED, 'The file each decision is appended to, as a JSON line');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $listen = $input->getOption('listen');
        $log = $input->getOption('log');
        if (!\is_string($listen) || !\is_string($log) || '' === $log) {
            $errors->writeln('network-proxy needs --listen and --log.');

            return Command::INVALID;
        }
        try {
            $address = ListenAddress::fromOption($listen);
        } catch (\InvalidArgumentException $e) {
            $errors->writeln('--listen: '.$e->getMessage());

            return Command::INVALID;
        }
        try {
            $server = $this->networkProxy->listen($address, AllowedHosts::registries(), new JsonLinesDecisionLog($log));
        } catch (NetworkStreamException $e) {
            $errors->writeln($e->getMessage());

            return Command::FAILURE;
        }
        $output->writeln($server->address());
        while (!$this->isStopping) {
            $server->tick(self::TICK_SECONDS);
        }
        $server->close();

        return Command::SUCCESS;
    }
}
