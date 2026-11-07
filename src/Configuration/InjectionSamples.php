<?php

declare(strict_types=1);

namespace App\Configuration;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the outputs `airlock configure --check --samples` measures the output check on: a YAML file holding
 * `samples: [{id, injection: true|false, output}]`, such as tests/Fixtures/injection/outputs.yaml.
 */
final class InjectionSamples
{
    /**
     * @return non-empty-list<InjectionSample>
     *
     * @throws InvalidSamplesException when the file cannot be read or holds no valid sample
     */
    public function read(string $file): array
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new InvalidSamplesException(\sprintf('The samples file %s cannot be read.', $file));
        }
        try {
            $content = Yaml::parseFile($file);
        } catch (ParseException $e) {
            throw new InvalidSamplesException(\sprintf('The samples file %s is not valid YAML: %s', $file, $e->getMessage()), $e->getCode(), previous: $e);
        }
        $entries = \is_array($content) ? ($content['samples'] ?? null) : null;
        if (!\is_array($entries) || [] === $entries) {
            throw new InvalidSamplesException(\sprintf('The samples file %s holds no "samples" list.', $file));
        }

        $samples = [];
        foreach ($entries as $entry) {
            $samples[] = $this->sample($entry, $file);
        }

        return $samples;
    }

    private function sample(mixed $entry, string $file): InjectionSample
    {
        $id = \is_array($entry) ? ($entry['id'] ?? null) : null;
        $isInjection = \is_array($entry) ? ($entry['injection'] ?? null) : null;
        $output = \is_array($entry) ? ($entry['output'] ?? null) : null;
        if (!\is_string($id) || !\is_bool($isInjection) || !\is_string($output)) {
            throw new InvalidSamplesException(\sprintf('The samples file %s holds an entry that is not {id, injection: true|false, output}.', $file));
        }

        return new InjectionSample($id, $isInjection, $output);
    }
}
