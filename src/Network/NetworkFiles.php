<?php

declare(strict_types=1);

namespace App\Network;

use App\Configuration\ConfigDirectory;
use App\Execution\ProjectDirectory;
use App\Policy\InvalidPolicyException;
use App\Policy\PolicyFiles;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads what a command limited to the registries may reach, every time: the registries, the hosts and local ports the
 * user's `network.yaml` files add (one for every project, one per project, both out of every command's reach), less
 * the hosts any policy file refuses. A project's committed `.airlock.yaml` is a policy file: it can take a host away,
 * never add one, since a command and the repository's author can both write it.
 */
final readonly class NetworkFiles
{
    public function __construct(
        private ConfigDirectory $configDirectory,
        private ProjectDirectory $projectDirectory,
        private PolicyFiles $policyFiles,
    ) {
    }

    /**
     * @throws InvalidNetworkFileException when a network file cannot be read: nothing runs until it is fixed
     * @throws InvalidPolicyException      when a policy file cannot be read
     */
    public function settings(): NetworkSettings
    {
        $allowedHosts = AllowedHosts::registries();
        $localPorts = [];
        foreach ($this->files() as $file) {
            $network = $this->read($file);
            foreach ($network['allow'] as $host) {
                $allowedHosts = $allowedHosts->with($this->allowedHost($host, $file));
            }
            foreach ($network['local'] as $port) {
                $localPorts[] = $this->localPort($port, $file);
            }
        }
        foreach ($this->policyFiles->refusedHosts() as $host) {
            $allowedHosts = $allowedHosts->without($host);
        }

        return new NetworkSettings($allowedHosts, array_values(array_unique($localPorts)));
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        return [
            $this->configDirectory->path.'/network.yaml',
            $this->configDirectory->forProject($this->projectDirectory->path).'/network.yaml',
        ];
    }

    /**
     * @return array{allow: list<string>, local: list<int>}
     *
     * @throws InvalidNetworkFileException
     */
    private function read(string $file): array
    {
        if (!is_file($file)) {
            return ['allow' => [], 'local' => []];
        }
        try {
            $content = Yaml::parseFile($file);
            $network = new Processor()->processConfiguration(new NetworkConfiguration(), null === $content ? [] : [$content]);
        } catch (ParseException|InvalidConfigurationException $e) {
            throw new InvalidNetworkFileException(\sprintf('The network file %s is invalid: %s', $file, $e->getMessage()), $e->getCode(), previous: $e);
        }

        $allow = [];
        foreach (\is_array($network['allow'] ?? null) ? $network['allow'] : [] as $host) {
            if (\is_string($host)) {
                $allow[] = $host;
            }
        }
        $local = [];
        foreach (\is_array($network['local'] ?? null) ? $network['local'] : [] as $port) {
            if (\is_int($port)) {
                $local[] = $port;
            }
        }

        return ['allow' => $allow, 'local' => $local];
    }

    /**
     * @throws InvalidNetworkFileException
     */
    private function allowedHost(string $host, string $file): ProxyTarget
    {
        try {
            return ProxyTarget::fromAuthority($host, defaultPort: 443);
        } catch (MalformedProxyRequestException $e) {
            throw new InvalidNetworkFileException(\sprintf('The network file %s allows "%s": %s Name one host, with its port when it is not 443; no wildcard.', $file, $host, $e->getMessage()), $e->getCode(), previous: $e);
        }
    }

    /**
     * @return int<1, 65535>
     *
     * @throws InvalidNetworkFileException
     */
    private function localPort(int $port, string $file): int
    {
        if ($port < 1 || $port > 65535) {
            throw new InvalidNetworkFileException(\sprintf('The network file %s lists the local port %d, which is no port.', $file, $port));
        }
        if (ProxyEndpoint::RELAY_PORT === $port) {
            throw new InvalidNetworkFileException(\sprintf('The network file %s lists the local port %d, which Airlock\'s relay takes inside the sandbox.', $file, $port));
        }

        return $port;
    }
}
