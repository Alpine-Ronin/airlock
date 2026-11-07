<?php

declare(strict_types=1);

namespace App\Command;

use App\Configuration\Calibration;
use App\Configuration\EffectiveModelSettings;
use App\Configuration\InjectionSample;
use App\Configuration\InjectionSamples;
use App\Configuration\InvalidModelSettingsException;
use App\Configuration\InvalidSamplesException;
use App\Configuration\ModelCalibrator;
use App\Configuration\ModelList;
use App\Configuration\ModelPreference;
use App\Configuration\ModelSettings;
use App\Configuration\ModelSettingsFile;
use App\Output\NotCheckedReason;
use App\Output\OutputCheck;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\MissingInputException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sets up the model checks in a terminal, never through the agent: the API and its key, then the models, found by
 * calibrating what the API lists (one model fit for both checks first, else the first fit for each; with --fastest, the
 * fastest of those among every model calibrated), or asked for when none passes. Writes them to `models.yaml`.
 */
#[AsCommand(name: 'configure', description: 'Set up the model checks: the API, its key and the models, calibrated before they are kept')]
final class ConfigureCommand extends Command
{
    /** How long to wait when the API's rate limit stops the calibration, before trying once more. */
    private const int RATE_LIMIT_PAUSE_SECONDS = 30;

    public function __construct(
        private readonly ModelSettingsFile $settingsFile,
        private readonly ModelList $modelList,
        private readonly ModelCalibrator $calibrator,
        private readonly ClockInterface $clock,
        private readonly EffectiveModelSettings $effectiveSettings,
        private readonly InjectionSamples $samples,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('check', null, InputOption::VALUE_NONE, 'Calibrate the models the server uses (environment variables first, then models.yaml), change nothing')
            ->addOption('samples', null, InputOption::VALUE_REQUIRED, 'With --check: measure the output check on these outputs instead, a YAML file such as tests/Fixtures/injection/outputs.yaml')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'The API, without /v1/chat/completions')
            ->addOption('output-check-model', null, InputOption::VALUE_REQUIRED, 'The model that checks outputs, instead of finding one')
            ->addOption('risk-model', null, InputOption::VALUE_REQUIRED, 'The model that writes the plain-words line, instead of finding one')
            ->addOption('fastest', null, InputOption::VALUE_NONE, 'Calibrate up to --max-probes models and keep the fastest that passes, instead of the first')
            ->addOption('max-probes', null, InputOption::VALUE_REQUIRED, 'How many models that answer to calibrate at most; one that gives no answer does not count', '15');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $current = $this->settingsFile->read();
        } catch (InvalidModelSettingsException $e) {
            $io->warning($e->getMessage().' It will be replaced.');
            $current = new ModelSettings();
        }

        $samplesFile = $this->option($input, 'samples');
        if (true === $input->getOption('check')) {
            $settings = $this->effectiveSettings->settings();

            return '' === $samplesFile ? $this->check($io, $settings) : $this->measure($io, $settings, $samplesFile);
        }
        if ('' !== $samplesFile) {
            $io->error('--samples goes with --check: airlock configure --check --samples <file>.');

            return Command::FAILURE;
        }

        $baseUrl = $this->baseUrl($input, $io, $current);
        $apiKey = $this->apiKey($io, $current);
        if ('' === $baseUrl || '' === $apiKey) {
            $io->error('An API URL and a key are both needed: nothing was saved.');

            return Command::FAILURE;
        }

        $outputCheckModel = $this->option($input, 'output-check-model');
        $riskModel = $this->option($input, 'risk-model');
        $calibrations = [];
        if ('' === $outputCheckModel || '' === $riskModel) {
            $listed = $this->listed($io, $baseUrl, $apiKey);
            $maxProbes = max(1, (int) $this->option($input, 'max-probes'));
            $io->section(\sprintf('Calibrating up to %d models that answer', $maxProbes));
            $preference = true === $input->getOption('fastest') ? ModelPreference::Fastest : ModelPreference::First;
            $bothFit = null;
            $outputCheckFit = null;
            $riskFit = null;
            $answered = 0;
            foreach ($listed as $model) {
                if ($answered >= $maxProbes) {
                    break;
                }
                $calibration = $this->calibrateThroughRateLimit($io, $baseUrl, $apiKey, $model);
                if ($calibration->isRateLimited()) {
                    $io->warning('The API\'s rate limit stopped the calibration. Run this command again in a minute, or name the models with --output-check-model and --risk-model.');
                    break;
                }
                $calibrations[$model] = $calibration;
                $this->report($io, $calibration);
                if ($calibration->isUnanswered()) {
                    continue;
                }
                ++$answered;
                if ($calibration->passesOutputCheck() && $preference->prefers($calibration, $outputCheckFit)) {
                    $outputCheckFit = $calibration;
                }
                if ($calibration->passesRiskLine() && $preference->prefers($calibration, $riskFit)) {
                    $riskFit = $calibration;
                }
                $passesBoth = $calibration->passesOutputCheck() && $calibration->passesRiskLine();
                if ($passesBoth && $preference->prefers($calibration, $bothFit)) {
                    $bothFit = $calibration;
                }
                if ($bothFit instanceof Calibration && ModelPreference::First === $preference) {
                    break;
                }
            }
            if ('' === $outputCheckModel) {
                $outputCheckModel = ($bothFit ?? $outputCheckFit)->model ?? '';
            }
            if ('' === $riskModel) {
                $riskModel = ($bothFit ?? $riskFit)->model ?? '';
            }
        }

        $outputCheckModel = $this->confirmed($io, 'output check', $outputCheckModel, $baseUrl, $apiKey, $calibrations, static fn (Calibration $c): bool => $c->passesOutputCheck());
        $riskModel = $this->confirmed($io, 'plain-words line', $riskModel, $baseUrl, $apiKey, $calibrations, static fn (Calibration $c): bool => $c->passesRiskLine());

        $this->settingsFile->write(new ModelSettings($baseUrl, $apiKey, $outputCheckModel, $riskModel));
        $io->success([
            \sprintf('Saved in %s, readable by you only and by no command Airlock runs.', $this->settingsFile->path()),
            \sprintf('Output check: %s. Plain-words line: %s.', '' === $outputCheckModel ? 'off' : $outputCheckModel, '' === $riskModel ? 'off' : $riskModel),
            'Restart your MCP client so that Airlock reads them. AIRLOCK_AI_* environment variables, when set, still win.',
        ]);

        return Command::SUCCESS;
    }

    private function check(SymfonyStyle $io, ModelSettings $settings): int
    {
        if ('' === $settings->baseUrl || '' === $settings->apiKey) {
            $io->error(\sprintf('No model API is configured, in %s or in AIRLOCK_AI_* environment variables: run this command without --check.', $this->settingsFile->path()));

            return Command::FAILURE;
        }

        $passes = true;
        foreach (array_unique(array_filter([$settings->outputCheckModel, $settings->riskModel], static fn (string $model): bool => '' !== $model)) as $model) {
            $calibration = $this->calibrateThroughRateLimit($io, $settings->baseUrl, $settings->apiKey, $model);
            $this->report($io, $calibration);
            $passes = $passes
                && ($model !== $settings->outputCheckModel || $calibration->passesOutputCheck())
                && ($model !== $settings->riskModel || $calibration->passesRiskLine());
        }

        return $passes ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * The output check's verdict on every sample, against what each holds: how many injections it flags, how many
     * clean outputs it flags too. Fails when one verdict is wrong or missing.
     */
    private function measure(SymfonyStyle $io, ModelSettings $settings, string $file): int
    {
        if ('' === $settings->baseUrl || '' === $settings->apiKey || '' === $settings->outputCheckModel) {
            $io->error(\sprintf('No output check is configured, in %s or in AIRLOCK_* environment variables: run this command without --check.', $this->settingsFile->path()));

            return Command::FAILURE;
        }
        try {
            $samples = $this->samples->read($file);
        } catch (InvalidSamplesException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->section(\sprintf('Measuring the output check of %s on %d samples', $settings->outputCheckModel, \count($samples)));
        $rows = [];
        $results = [];
        foreach ($samples as $sample) {
            $check = $this->checkThroughRateLimit($io, $settings, $sample);
            $results[] = [$sample, $check];
            $rows[] = [$sample->id, $sample->isInjection ? 'injection' : 'clean', $this->verdict($check), $this->isRight($sample, $check) ? '✔' : '✘'];
        }
        $io->table(['Sample', 'Holds', 'Output check', 'Right'], $rows);
        $io->text($this->summary($results));

        $isEveryVerdictRight = array_all($results, fn (array $result): bool => $this->isRight(...$result));

        return $isEveryVerdictRight ? Command::SUCCESS : Command::FAILURE;
    }

    private function checkThroughRateLimit(SymfonyStyle $io, ModelSettings $settings, InjectionSample $sample): OutputCheck
    {
        $check = $this->calibrator->checkOutput($settings->baseUrl, $settings->apiKey, $settings->outputCheckModel, $sample->output);
        if (NotCheckedReason::RateLimited !== $check->reason) {
            return $check;
        }

        $io->text(\sprintf('%s  rate limited: waiting %d seconds before trying once more', $sample->id, self::RATE_LIMIT_PAUSE_SECONDS));
        $this->clock->sleep(self::RATE_LIMIT_PAUSE_SECONDS);

        return $this->calibrator->checkOutput($settings->baseUrl, $settings->apiKey, $settings->outputCheckModel, $sample->output);
    }

    private function verdict(OutputCheck $check): string
    {
        if ($check->reason instanceof NotCheckedReason) {
            return 'not checked ('.$check->reason->describe().')';
        }

        return $check->isInjection() ? 'injection flagged' : 'clean';
    }

    private function isRight(InjectionSample $sample, OutputCheck $check): bool
    {
        return !$check->reason instanceof NotCheckedReason && $check->isInjection() === $sample->isInjection;
    }

    /**
     * @param list<array{InjectionSample, OutputCheck}> $results
     */
    private function summary(array $results): string
    {
        $injections = 0;
        $injectionsFlagged = 0;
        $cleanFlagged = 0;
        $notChecked = 0;
        foreach ($results as [$sample, $check]) {
            $injections += $sample->isInjection ? 1 : 0;
            $injectionsFlagged += $sample->isInjection && $check->isInjection() ? 1 : 0;
            $cleanFlagged += !$sample->isInjection && $check->isInjection() ? 1 : 0;
            $notChecked += $check->reason instanceof NotCheckedReason ? 1 : 0;
        }

        return \sprintf(
            'Injections flagged: %d of %d. Clean outputs flagged: %d of %d. Not checked: %d.',
            $injectionsFlagged,
            $injections,
            $cleanFlagged,
            \count($results) - $injections,
            $notChecked,
        );
    }

    private function baseUrl(InputInterface $input, SymfonyStyle $io, ModelSettings $current): string
    {
        $given = $this->option($input, 'base-url');
        if ('' !== $given) {
            return rtrim($given, '/');
        }
        $answer = $io->ask('URL of the chat completions API, without /v1/chat/completions', '' === $current->baseUrl ? null : $current->baseUrl);

        return \is_string($answer) ? rtrim(trim($answer), '/') : '';
    }

    private function apiKey(SymfonyStyle $io, ModelSettings $current): string
    {
        $prompt = '' === $current->apiKey ? 'API key (not shown as you type)' : 'API key (not shown as you type; Enter keeps the current one)';
        $answer = $io->askHidden($prompt);

        return \is_string($answer) && '' !== trim($answer) ? trim($answer) : $current->apiKey;
    }

    /**
     * @return list<string>
     */
    private function listed(SymfonyStyle $io, string $baseUrl, #[\SensitiveParameter] string $apiKey): array
    {
        try {
            $models = $this->modelList->of($baseUrl, $apiKey);
        } catch (\RuntimeException $e) {
            $io->warning($e->getMessage());

            return [];
        }
        $io->text(\sprintf('The API lists %d models.', \count($models)));

        return $models;
    }

    /**
     * The model to keep for one check: the one calibration found, else one the user names, calibrated, and kept only
     * if it passes or the user insists. Empty leaves the check off.
     *
     * @param array<string, Calibration>  $calibrations
     * @param \Closure(Calibration): bool $passes
     */
    private function confirmed(SymfonyStyle $io, string $check, string $model, string $baseUrl, #[\SensitiveParameter] string $apiKey, array $calibrations, \Closure $passes): string
    {
        $calibration = $calibrations[$model] ?? null;
        if ('' !== $model && $calibration instanceof Calibration && $passes($calibration)) {
            return $model;
        }
        if ('' === $model) {
            $io->note(\sprintf('No listed model passed for the %s.', $check));
            $question = new Question(\sprintf('Model for the %s (Enter leaves it off)', $check));
            $question->setAutocompleterValues(array_keys($calibrations));
            $model = $this->answerText($io, $question);
        }
        if ('' === $model) {
            return '';
        }

        $calibration = $this->calibrateThroughRateLimit($io, $baseUrl, $apiKey, $model);
        $this->report($io, $calibration);
        if ($passes($calibration)) {
            return $model;
        }

        return $this->answerYes($io, new ConfirmationQuestion(\sprintf('%s failed the %s calibration. Keep it anyway?', $model, $check), false)) ? $model : '';
    }

    private function calibrateThroughRateLimit(SymfonyStyle $io, string $baseUrl, #[\SensitiveParameter] string $apiKey, string $model): Calibration
    {
        $calibration = $this->calibrator->calibrate($baseUrl, $apiKey, $model);
        if (!$calibration->isRateLimited()) {
            return $calibration;
        }

        $io->text(\sprintf('%s  rate limited: waiting %d seconds before trying once more', $model, self::RATE_LIMIT_PAUSE_SECONDS));
        $this->clock->sleep(self::RATE_LIMIT_PAUSE_SECONDS);

        return $this->calibrator->calibrate($baseUrl, $apiKey, $model);
    }

    /**
     * The answer, or nothing when the input has ended: an unanswered question leaves the check off.
     */
    private function answerText(SymfonyStyle $io, Question $question): string
    {
        try {
            $answer = $io->askQuestion($question);
        } catch (MissingInputException) {
            return '';
        }

        return \is_string($answer) ? trim($answer) : '';
    }

    /**
     * Yes only when the user says so: an ended input is a no.
     */
    private function answerYes(SymfonyStyle $io, ConfirmationQuestion $question): bool
    {
        try {
            return true === $io->askQuestion($question);
        } catch (MissingInputException) {
            return false;
        }
    }

    private function report(SymfonyStyle $io, Calibration $calibration): void
    {
        if ($calibration->isUnanswered()) {
            $io->text(\sprintf('%s  %s', $calibration->model, $calibration->outputCheckFailure));

            return;
        }
        $io->text(\sprintf(
            '%s  output check: %s · plain-words line: %s · %.1f s',
            $calibration->model,
            $calibration->outputCheckFailure ?? 'passes',
            $calibration->riskLineFailure ?? 'passes',
            $calibration->seconds,
        ));
    }

    private function option(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        return \is_string($value) ? trim($value) : '';
    }
}
