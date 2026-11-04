<?php

declare(strict_types=1);

namespace App\Doctor;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One thing `airlock doctor` looks at, without changing anything and without reaching the network.
 */
#[AutoconfigureTag]
interface Check
{
    public function run(): Finding;
}
