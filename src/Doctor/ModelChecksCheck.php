<?php

declare(strict_types=1);

namespace App\Doctor;

use App\Audit\OutputCheckTimeouts;
use App\Audit\UnreadableAuditLogException;
use App\Configuration\EffectiveModelSettings;
use App\Configuration\InvalidModelSettingsException;
use App\Configuration\ModelSettingsFile;
use App\Execution\ProjectDirectory;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Whether the output check and the plain-words line are on, from the settings the server would read, and whether the
 * output check keeps timing out in the project, from the audit log. Calibrating the models takes the network:
 * `airlock configure --check` does it.
 */
#[AsTaggedItem(priority: 50)]
final readonly class ModelChecksCheck implements Check
{
    private const string TOPIC = 'Model checks';

    public function __construct(
        private ModelSettingsFile $file,
        private EffectiveModelSettings $effectiveSettings,
        private OutputCheckTimeouts $outputCheckTimeouts,
        private ProjectDirectory $projectDirectory,
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
        if ('' !== $settings->outputCheckModel) {
            try {
                $outputChecks = $this->outputCheckTimeouts->recent($this->projectDirectory->path);
            } catch (UnreadableAuditLogException $e) {
                return Finding::warning(self::TOPIC, $text.' '.$e->getMessage().' Whether the output check keeps timing out is unknown.');
            }
            if ($outputChecks->keepTimingOut()) {
                return Finding::warning(self::TOPIC, $text.' '.$outputChecks->warning());
            }
        }
        if ('' === $settings->outputCheckModel || '' === $settings->riskModel) {
            return Finding::warning(self::TOPIC, $text);
        }

        return Finding::ok(self::TOPIC, $text);
    }
}
