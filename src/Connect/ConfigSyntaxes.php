<?php

declare(strict_types=1);

namespace App\Connect;

final readonly class ConfigSyntaxes
{
    public function __construct(
        private JsonConfig $json,
        private YamlConfig $yaml,
        private TomlConfig $toml,
        private FrontMatterConfig $frontMatter,
    ) {
    }

    public function for(ConfigFormat $format): ConfigSyntax
    {
        return match ($format) {
            ConfigFormat::Json => $this->json,
            ConfigFormat::Yaml => $this->yaml,
            ConfigFormat::Toml => $this->toml,
            ConfigFormat::FrontMatter => $this->frontMatter,
        };
    }
}
