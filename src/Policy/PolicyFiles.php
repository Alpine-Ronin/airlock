<?php

declare(strict_types=1);

namespace App\Policy;

use App\Configuration\ConfigDirectory;
use App\Execution\ProjectDirectory;
use App\Network\MalformedProxyRequestException;
use App\Network\ProxyTarget;
use App\Shell\ShellParser;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads what is refused, commands and hosts, from three files, every time: they can change while Airlock runs.
 *
 * Two are the user's, in Airlock's directory, out of every command's reach: `policy.yaml` for every project, and one
 * per project. The third, `.airlock.yaml`, is committed with the project so a team shares it; a command can write to
 * it, so it can only add refusals, never lift one or grant anything.
 */
final readonly class PolicyFiles
{
    public const string COMMITTED_FILE = '.airlock.yaml';

    public function __construct(
        private ConfigDirectory $configDirectory,
        private ProjectDirectory $projectDirectory,
        private ShellParser $parser,
    ) {
    }

    /**
     * @return list<RefusedCommand>
     *
     * @throws InvalidPolicyException when a policy file cannot be read: nothing runs until it is fixed
     */
    public function refusals(): array
    {
        $refusals = [];
        foreach ($this->files() as $file) {
            foreach ($this->entries($file, 'refuse') as $commandLine) {
                $refusals[] = $this->refusedCommand($commandLine, $file);
            }
        }

        return $refusals;
    }

    /**
     * The host names no sandboxed command reaches, whatever else allows them.
     *
     * @return list<string>
     *
     * @throws InvalidPolicyException when a policy file cannot be read: nothing runs until it is fixed
     */
    public function refusedHosts(): array
    {
        $hosts = [];
        foreach ($this->files() as $file) {
            foreach ($this->entries($file, 'refuse_hosts') as $host) {
                $hosts[] = $this->refusedHost($host, $file);
            }
        }

        return $hosts;
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $project = $this->projectDirectory->path;

        return [
            $this->configDirectory->path.'/policy.yaml',
            $this->configDirectory->forProject($project).'/policy.yaml',
            $project.'/'.self::COMMITTED_FILE,
        ];
    }

    /**
     * The strings a policy file lists under a key.
     *
     * @return list<string>
     *
     * @throws InvalidPolicyException
     */
    private function entries(string $file, string $key): array
    {
        if (!is_file($file)) {
            return [];
        }

        try {
            $content = Yaml::parseFile($file);
            $policy = new Processor()->processConfiguration(new PolicyConfiguration(), null === $content ? [] : [$content]);
        } catch (ParseException|InvalidConfigurationException $e) {
            throw new InvalidPolicyException(\sprintf('The policy file %s is invalid: %s', $file, $e->getMessage()), $e->getCode(), previous: $e);
        }

        $entries = [];
        $listed = $policy[$key] ?? [];
        foreach (\is_array($listed) ? $listed : [] as $entry) {
            if (\is_string($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @throws InvalidPolicyException
     */
    private function refusedHost(string $host, string $file): string
    {
        try {
            $target = ProxyTarget::fromAuthority($host, defaultPort: 443);
        } catch (MalformedProxyRequestException $e) {
            throw new InvalidPolicyException(\sprintf('The policy file %s refuses the host "%s": %s', $file, $host, $e->getMessage()), $e->getCode(), previous: $e);
        }
        if (str_contains($host, ':')) {
            throw new InvalidPolicyException(\sprintf('The policy file %s refuses the host "%s": name the host alone, it is refused on every port.', $file, $host));
        }

        return $target->host;
    }

    private function refusedCommand(string $refused, string $file): RefusedCommand
    {
        $line = $this->parser->parse($refused);
        $words = 1 === \count($line->commands) && !$line->isOpaque() ? $line->commands[0]->programWords() : [];
        if ([] === $words) {
            throw new InvalidPolicyException(\sprintf('The policy file %s refuses "%s", which is not one plain command.', $file, $refused));
        }

        return new RefusedCommand($words, $file);
    }
}
