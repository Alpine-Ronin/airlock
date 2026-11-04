<?php

declare(strict_types=1);

namespace App\Connect;

/**
 * A setting on one of the client's own entries, such as its developer extension. A client that adds the entry by
 * default when its configuration has none would add it without the setting: Airlock then writes the entry as the
 * client would, with the setting. Disconnecting puts the setting back as it was, and removes an entry Airlock wrote
 * while it holds nothing else.
 */
final readonly class SetInClientEntry implements ConfigChange
{
    /**
     * @param bool|int|string|list<string>|ConfigTable $value
     */
    public function __construct(
        private ConfigPath $path,
        public bool|int|string|array|ConfigTable $value,
        private ConfigTable $entryByDefault,
    ) {
    }

    #[\Override]
    public function path(): ConfigPath
    {
        return $this->path;
    }

    #[\Override]
    public function connect(\stdClass $root): void
    {
        $entry = $this->path->existingParent($root);
        if (!$entry instanceof \stdClass) {
            $entryPath = $this->path->parent();
            $entry = ConfigNode::table($this->entryByDefault);
            $entryPath->parentOrCreate($root)->{$entryPath->key()} = $entry;
        }
        $entry->{$this->path->key()} = ConfigNode::of($this->value);
    }

    #[\Override]
    public function disconnect(\stdClass $root, ?\stdClass $original): void
    {
        if (!$original instanceof \stdClass) {
            return;
        }
        $entryPath = $this->path->parent();
        if ($this->holds($original, $entryPath)) {
            SharedValue::restore($this->path, $root, $original);

            return;
        }
        $this->takeBackEntry($root, $entryPath);
    }

    /**
     * Only Airlock's setting goes; the entry with it, only while it holds nothing but what Airlock wrote: a field
     * added to it since, by the user or the client, stays.
     */
    private function takeBackEntry(\stdClass $root, ConfigPath $entryPath): void
    {
        $parent = $entryPath->existingParent($root);
        if (!$parent instanceof \stdClass || !property_exists($parent, $entryPath->key())) {
            return;
        }
        $entry = $parent->{$entryPath->key()};
        if (!$entry instanceof \stdClass) {
            return;
        }
        unset($entry->{$this->path->key()});
        if ($this->isSameTable($entry, ConfigNode::table($this->entryByDefault))) {
            unset($parent->{$entryPath->key()});
        }
    }

    private function isSameTable(\stdClass $table, \stdClass $other): bool
    {
        $entries = get_object_vars($table);
        $otherEntries = get_object_vars($other);
        if (\count($entries) !== \count($otherEntries)) {
            return false;
        }
        return array_all($entries, fn($value, $key): bool => \array_key_exists($key, $otherEntries) && $this->isSameValue($value, $otherEntries[$key]));
    }

    private function isSameValue(mixed $value, mixed $other): bool
    {
        if ($value instanceof \stdClass && $other instanceof \stdClass) {
            return $this->isSameTable($value, $other);
        }

        return $value === $other;
    }

    private function holds(\stdClass $root, ConfigPath $path): bool
    {
        $parent = $path->existingParent($root);

        return $parent instanceof \stdClass && property_exists($parent, $path->key());
    }

    #[\Override]
    public function isShared(): bool
    {
        return true;
    }

    #[\Override]
    public function describe(Direction $direction): string
    {
        return match ($direction) {
            Direction::Connect => \sprintf('sets %s, writing %s as the client would when it is not there', $this->path->dotted(), $this->path->parent()->dotted()),
            Direction::Disconnect => \sprintf('puts %s back as it was', $this->path->dotted()),
        };
    }
}
