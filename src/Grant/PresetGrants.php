<?php

declare(strict_types=1);

namespace App\Grant;

use App\Execution\ProjectDirectory;
use App\Shell\ShellParser;

/**
 * The grants a preset gives, each bound to the project files its command reads now, like any grant; and the presets a
 * project's files suggest.
 */
final readonly class PresetGrants
{
    public function __construct(
        private CommandSources $sources,
        private ProjectDirectory $projectDirectory,
        private ShellParser $parser,
    ) {
    }

    /**
     * @return non-empty-list<Grant>
     */
    public function for(Preset $preset): array
    {
        $grants = [];
        foreach ($preset->commands() as $words) {
            $sources = $this->sources->of($this->parser->parse(implode(' ', $words)));
            $grants[] = new Grant($words, coversLongerCommands: true, sources: $sources[0] ?? []);
        }

        return $grants;
    }

    /**
     * @return list<Preset>
     */
    public function suggestedFor(): array
    {
        $suggested = [];
        foreach (Preset::cases() as $preset) {
            $isMarked = array_any($preset->markers(), fn (string $marker): bool => is_file($this->projectDirectory->path.'/'.$marker));
            if ($isMarked) {
                $suggested[] = $preset;
            }
        }

        return $suggested;
    }
}
