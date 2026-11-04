<?php

declare(strict_types=1);

namespace App\Connect;

/**
 * Where a setting sits in a client's configuration: `mcpServers.airlock`, `permissions.deny`.
 */
final readonly class ConfigPath
{
    /**
     * @param non-empty-list<non-empty-string> $keys
     */
    private function __construct(public array $keys)
    {
    }

    /**
     * @param non-empty-string $first
     * @param non-empty-string ...$rest
     */
    public static function of(string $first, string ...$rest): self
    {
        return new self([$first, ...array_values($rest)]);
    }

    public function key(): string
    {
        return $this->keys[\count($this->keys) - 1];
    }

    /**
     * @throws \LogicException for a path of one key, which has no parent
     */
    public function parent(): self
    {
        $keys = $this->parentKeys();
        if ([] === $keys) {
            throw new \LogicException(\sprintf('%s has no parent', $this->dotted()));
        }

        return new self($keys);
    }

    public function dotted(): string
    {
        return implode('.', $this->keys);
    }

    /**
     * @throws UnsupportedConfigException when a key on the way holds something other than a table
     */
    public function parentOrCreate(\stdClass $root): \stdClass
    {
        $node = $root;
        foreach ($this->parentKeys() as $key) {
            if (!property_exists($node, $key)) {
                $node->{$key} = new \stdClass();
            }
            $node = $this->table($node->{$key}, $key);
        }

        return $node;
    }

    /**
     * @throws UnsupportedConfigException when a key on the way holds something other than a table
     */
    public function existingParent(\stdClass $root): ?\stdClass
    {
        $node = $root;
        foreach ($this->parentKeys() as $key) {
            if (!property_exists($node, $key)) {
                return null;
            }
            $node = $this->table($node->{$key}, $key);
        }

        return $node;
    }

    /**
     * @return list<non-empty-string>
     */
    private function parentKeys(): array
    {
        return \array_slice($this->keys, 0, -1);
    }

    private function table(mixed $value, string $key): \stdClass
    {
        if (!$value instanceof \stdClass) {
            throw new UnsupportedConfigException(\sprintf('"%s" on the way to %s is not a table', $key, $this->dotted()));
        }

        return $value;
    }
}
