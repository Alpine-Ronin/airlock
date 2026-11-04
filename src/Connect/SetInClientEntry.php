<?php

declare(strict_types=1);

namespace App\Connect;

/**
 * A setting on one of the client's own entries, such as its developer extension. A client that adds the entry by
 * default when its configuration has none would add it without the setting: Airlock then writes the entry as the
 * client would, with the setting. Disconnecting puts the setting back as it was, and removes an entry Airlock wrote.
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
        $parent = $entryPath->existingParent($root);
        if ($parent instanceof \stdClass) {
            unset($parent->{$entryPath->key()});
        }
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
