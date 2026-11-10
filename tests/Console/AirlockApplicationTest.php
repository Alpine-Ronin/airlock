<?php

declare(strict_types=1);

namespace App\Tests\Console;

use App\Console\AirlockApplication;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Tester\ApplicationTester;

final class AirlockApplicationTest extends KernelTestCase
{
    public function testAirlockAloneSaysWhatItIsAndListsOnlyItsOwnCommands(): void
    {
        $tester = $this->tester();

        $status = $tester->run([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('every agent command passes through it', $tester->getDisplay());
        self::assertStringContainsString('airlock connect claude-code', $tester->getDisplay());
        self::assertMatchesRegularExpression('/^  doctor\s+Check that Airlock can protect this project/m', $tester->getDisplay());
        self::assertStringNotContainsString('cache:clear', $tester->getDisplay(), 'The framework\'s commands are not Airlock\'s to offer.');
    }

    public function testHelpWithoutACommandShowsTheSameAsAirlockAlone(): void
    {
        $tester = $this->tester();
        $tester->run([]);
        $alone = $tester->getDisplay();

        $tester->run(['--help' => true]);

        self::assertSame($alone, $tester->getDisplay());
    }

    public function testTheVersionAskedForWithHelpIsAllThatIsShown(): void
    {
        $tester = $this->tester();

        $tester->run(['--version' => true, '--help' => true]);

        self::assertMatchesRegularExpression('/^\S+ \S+ \(env: test, debug: \w+\)\n$/', $tester->getDisplay());
        self::assertStringNotContainsString('Examples', $tester->getDisplay(), 'As in any Symfony application, --version wins over --help.');
    }

    public function testAHiddenCommandStillRunsWhenNamed(): void
    {
        $tester = $this->tester();

        $status = $tester->run(['command' => 'about']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Symfony', $tester->getDisplay());
    }

    public function testEveryCommandOfAirlockIsListed(): void
    {
        $application = new AirlockApplication(self::bootKernel());

        foreach ($application->all() as $name => $command) {
            $class = ($command instanceof LazyCommand ? $command->getCommand() : $command)::class;
            if (str_starts_with($class, 'App\\') && !\in_array($name, AirlockApplication::INTERNAL_COMMANDS, true)) {
                self::assertFalse($command->isHidden(), \sprintf('%s is Airlock\'s: add it to AirlockApplication::LISTED_COMMANDS and to the examples.', $name));
            }
        }
    }

    public function testEveryOtherCommandIsHidden(): void
    {
        $application = new AirlockApplication(self::bootKernel());

        foreach ($application->all() as $name => $command) {
            if (!\in_array($command->getName(), AirlockApplication::LISTED_COMMANDS, true)) {
                self::assertTrue($command->isHidden(), \sprintf('%s is not one of Airlock\'s commands.', $name));
            }
        }
    }

    private function tester(): ApplicationTester
    {
        $application = new AirlockApplication(self::bootKernel());
        $application->setAutoExit(false);

        return new ApplicationTester($application);
    }
}
