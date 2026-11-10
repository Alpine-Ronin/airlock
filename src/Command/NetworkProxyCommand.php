<?php

declare(strict_types=1);

namespace App\Command;

use App\Network\AllowedHosts;
use App\Network\JsonLinesDecisionLog;
use App\Network\ListenAddress;
use App\Network\MalformedProxyRequestException;
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
 * The proxy to the hosts a sandboxed command may reach, served until it is stopped. Airlock starts it for a command,
 * naming each host, and stops it after: it prints where it listens, then one JSON line per decision goes to the log.
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
        // Without pcntl (the official PHP image has none), SIGTERM ends the proxy at once, as it does any program:
        // every decision is already written, and the runner removes the proxy's directory.
        if (!\extension_loaded('pcntl')) {
            return [];
        }

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
            ->addOption('log', null, InputOption::VALUE_REQUIRED, 'The file each decision is appended to, as a JSON line')
            ->addOption('allow', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A host the command may reach, as host:port; none by default')
            ->addOption('local-port', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A port on 127.0.0.1 the command may reach as localhost');
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
            $allowedHosts = AllowedHosts::fromAuthorities($this->strings($input->getOption('allow')));
        } catch (MalformedProxyRequestException $e) {
            $errors->writeln('--allow: '.$e->getMessage());

            return Command::INVALID;
        }
        $localPorts = $this->ports($this->strings($input->getOption('local-port')));
        if (null === $localPorts) {
            $errors->writeln('--local-port takes a port number.');

            return Command::INVALID;
        }
        try {
            $server = $this->networkProxy->listen($address, $allowedHosts, new JsonLinesDecisionLog($log), $localPorts);
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

    /**
     * @return list<string>
     */
    private function strings(mixed $option): array
    {
        $strings = [];
        foreach (\is_array($option) ? $option : [] as $value) {
            if (\is_string($value)) {
                $strings[] = $value;
            }
        }

        return $strings;
    }

    /**
     * The ports, or null when one is not a port.
     *
     * @param list<string> $values
     *
     * @return list<int>|null
     */
    private function ports(array $values): ?array
    {
        $ports = [];
        foreach ($values as $value) {
            if (1 !== preg_match('/^[1-9][0-9]{0,4}$/', $value) || (int) $value > 65535) {
                return null;
            }
            $ports[] = (int) $value;
        }

        return $ports;
    }
}
