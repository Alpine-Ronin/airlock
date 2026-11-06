<?php

declare(strict_types=1);

namespace App\Connect;

use Symfony\Component\Yaml\Yaml;

/**
 * A Markdown file whose settings are its YAML front matter, such as a VS Code agent. The front matter is edited as a
 * YAML file is; the Markdown after it is kept as it was.
 */
final readonly class FrontMatterConfig implements ConfigSyntax
{
    private const string FENCE = "---\n";

    public function __construct(
        private YamlConfig $yaml,
    ) {
    }

    #[\Override]
    public function connect(string $content, array $changes): string
    {
        [$frontMatter, $body] = $this->split($content);

        return $this->joined($this->yaml->connect($frontMatter, $changes), $body);
    }

    #[\Override]
    public function disconnect(string $content, array $changes, ?string $original): string
    {
        [$frontMatter, $body] = $this->split($content);
        $originalFrontMatter = null === $original ? null : $this->split($original)[0];

        return $this->joined($this->yaml->disconnect($frontMatter, $changes, $originalFrontMatter), $body);
    }

    /**
     * @return array{string, string} the front matter, '' when there is none, and the Markdown after it
     *
     * @throws UnsupportedConfigException
     */
    private function split(string $content): array
    {
        if (!str_starts_with($content, '---')) {
            return ['', $content];
        }
        if (1 !== preg_match('/\A---\n(.*?)^---(?:\n|\z)/sm', $content, $match)) {
            throw new UnsupportedConfigException('its front matter is not between two lines holding only ---');
        }

        return [$match[1], substr($content, \strlen($match[0]))];
    }

    private function joined(string $frontMatter, string $body): string
    {
        $settings = Yaml::parse($frontMatter);
        if (null === $settings || [] === $settings) {
            return $body;
        }

        return self::FENCE.rtrim($frontMatter, "\n")."\n".self::FENCE.$body;
    }
}
