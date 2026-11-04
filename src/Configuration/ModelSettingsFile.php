<?php

declare(strict_types=1);

namespace App\Configuration;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * `models.yaml`, in Airlock's directory: out of every command's reach, and readable by its owner only, since it holds
 * the API key.
 */
final readonly class ModelSettingsFile
{
    public function __construct(
        private ConfigDirectory $configDirectory,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function path(): string
    {
        return $this->configDirectory->path.'/models.yaml';
    }

    /**
     * @throws InvalidModelSettingsException when the file exists but does not hold settings
     */
    public function read(): ModelSettings
    {
        $path = $this->path();
        if (!is_file($path)) {
            return new ModelSettings();
        }

        try {
            $content = Yaml::parseFile($path);
        } catch (ParseException $e) {
            // The parser's message repeats the line it stopped at, which can be the key's.
            throw new InvalidModelSettingsException(\sprintf('%s is not valid YAML at line %d.', $path, $e->getParsedLine()), $e->getCode(), previous: $e);
        }
        if (!\is_array($content) || ([] !== $content && array_is_list($content))) {
            throw new InvalidModelSettingsException(\sprintf('%s holds no settings.', $path));
        }

        $values = [];
        foreach (ModelSetting::cases() as $setting) {
            $value = $content[$setting->value] ?? '';
            if (!\is_string($value)) {
                throw new InvalidModelSettingsException(\sprintf('%s: "%s" is not a string.', $path, $setting->value));
            }
            $values[$setting->value] = $value;
        }

        return new ModelSettings($values['base_url'], $values['api_key'], $values['output_check_model'], $values['risk_model']);
    }

    public function write(ModelSettings $settings): void
    {
        $path = $this->path();
        $this->filesystem->mkdir($this->configDirectory->path, 0o700);
        $this->filesystem->chmod($this->configDirectory->path, 0o700);
        if (!is_file($path)) {
            $this->filesystem->dumpFile($path, '');
        }
        $this->filesystem->chmod($path, 0o600);
        $this->filesystem->dumpFile($path, Yaml::dump($settings->toArray()));
    }
}
