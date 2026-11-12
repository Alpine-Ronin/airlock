<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class UpdateCommandTest extends KernelTestCase
{
    public function testAnAirlockRunFromACheckoutSaysHowToUpdateIt(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('update'));

        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('git pull', $tester->getDisplay());
    }

    public function testAVersionThatIsNotThreeNumbersIsRefused(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('update'));

        $tester->execute(['version' => 'latest; rm -rf ~']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('three numbers', $tester->getDisplay());
    }
}
