<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use App\Configuration\ConfigDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigDirectoryTest extends TestCase
{
    #[DataProvider('homes')]
    public function testTheDirectoryIsWrittenWithoutADoubleSlash(string $home, ?string $configHome, string $expected): void
    {
        self::assertSame($expected, new ConfigDirectory($home, $configHome)->path);
    }

    /**
     * @return iterable<string, array{string, ?string, string}>
     */
    public static function homes(): iterable
    {
        yield 'a home' => ['/home/me', null, '/home/me/.config/airlock'];
        yield 'the root as home, as in a container' => ['/', null, '/.config/airlock'];
        yield 'a home ending in a slash' => ['/home/me/', null, '/home/me/.config/airlock'];
        yield 'XDG_CONFIG_HOME, which wins' => ['/home/me', '/srv/config', '/srv/config/airlock'];
        yield 'XDG_CONFIG_HOME ending in a slash' => ['/home/me', '/srv/config/', '/srv/config/airlock'];
        yield 'an empty XDG_CONFIG_HOME, which is not set' => ['/home/me', '', '/home/me/.config/airlock'];
    }
}
