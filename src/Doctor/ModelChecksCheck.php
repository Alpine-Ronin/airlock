<?php

declare(strict_types=1);

namespace App\Doctor;

use App\Configuration\EffectiveModelSettings;
use App\Configuration\InvalidModelSettingsException;
use App\Configuration\ModelSettingsFile;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Whether the output check and the plain-words line are on, from the settings the server would read. Calibrating the
 * models takes the network: `airlock configure --check` does it.
 */
#[AsTaggedItem(priority: 50)]
final readonly class ModelChecksCheck implements Check
{
    private const string TOPIC = 'Model checks';

    public function __construct(
        private ModelSettingsFile $file,
        private EffectiveModelSettings $effectiveSettings,
    ) {
    }

    #[\Override]
    public function run(): Finding
    {
        try {
            $this->file->read();
        } catch (InvalidModelSettingsException $e) {
            return Finding::problem(self::TOPIC, $e->getMessage().' The model checks are off until it is fixed, or replaced with airlock configure.');
        }

        $settings = $this->effectiveSettings->settings();
        if ('' === $settings->baseUrl || '' === $settings->apiKey) {
            return Finding::warning(self::TOPIC, 'Off: no model API is configured, so outputs are not checked for injections and commands get no plain-words line. Run airlock configure to turn them on.');
        }

        $text = \sprintf(
            'Through %s. Output check: %s. Plain-words line: %s. Run airlock configure --check to calibrate them.',
            $settings->baseUrl,
            '' === $settings->outputCheckModel ? 'off' : $settings->outputCheckModel,
            '' === $settings->riskModel ? 'off' : $settings->riskModel,
        );
        if ('' === $settings->outputCheckModel || '' === $settings->riskModel) {
            return Finding::warning(self::TOPIC, $text);
        }

        return Finding::ok(self::TOPIC, $text);
    }
}
