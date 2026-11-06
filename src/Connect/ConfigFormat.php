<?php

declare(strict_types=1);

namespace App\Connect;

enum ConfigFormat
{
    case Json;
    case Yaml;
    case Toml;
    case FrontMatter;
}
