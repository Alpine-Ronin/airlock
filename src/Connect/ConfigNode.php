<?php

declare(strict_types=1);

namespace App\Connect;

final class ConfigNode
{
    /**
     * A value as decoded JSON or YAML holds it: tables as objects, so that an empty one stays a table.
     *
     * @param bool|int|string|list<string>|ConfigTable $value
     *
     * @return bool|int|string|list<string>|\stdClass
     */
    public static function of(bool|int|string|array|ConfigTable $value): bool|int|string|array|\stdClass
    {
        if (!$value instanceof ConfigTable) {
            return $value;
        }

        return self::table($value);
    }

    public static function table(ConfigTable $table): \stdClass
    {
        $node = new \stdClass();
        foreach ($table->entries as $key => $entry) {
            $node->{$key} = self::of($entry);
        }

        return $node;
    }
}
