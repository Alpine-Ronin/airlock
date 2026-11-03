<?php

declare(strict_types=1);

namespace App;

use Composer\InstalledVersions;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Airlock's version is the root package's: Composer takes it from the git tag a release is built from. Set here
     * rather than in getKernelParameters(), whose parent would no longer see getAllowedEnvs() below.
     */
    #[\Override]
    protected function build(ContainerBuilder $container): void
    {
        $container->setParameter('airlock.version', InstalledVersions::getRootPackage()['pretty_version']);
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
