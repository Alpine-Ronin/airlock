<?php

declare(strict_types=1);

namespace App;

use Composer\InstalledVersions;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Airlock's version is the root package's: Composer takes it from the git tag a release is built from.
     *
     * @return array<mixed>
     */
    #[\Override]
    protected function getKernelParameters(): array
    {
        return [...parent::getKernelParameters(), 'airlock.version' => InstalledVersions::getRootPackage()['pretty_version']];
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
