<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Network\Streams;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class NetworkProxyCommandTest extends KernelTestCase
{
    private const string ROOT = __DIR__.'/../../var/test-network-proxy-command';

    private const string LOG = self::ROOT.'/decisions.jsonl';

    #[\Override]
    protected function setUp(): void
    {
        $filesystem = new Filesystem();
        $filesystem->remove(self::ROOT);
        $filesystem->mkdir(self::ROOT);
    }

    public function testTheProxyBlocksAHostOffTheListLogsItAndStopsOnSigterm(): void
    {
        $proxy = new Process([\PHP_BINARY, __DIR__.'/../../bin/console', 'network-proxy', '--listen', '127.0.0.1:0', '--log', self::LOG]);
        $proxy->start();
        $proxy->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "\n"));
        $address = trim($proxy->getOutput());
        self::assertMatchesRegularExpression('/^127\.0\.0\.1:[0-9]+$/', $address);

        $command = Streams::client('tcp://'.$address, 2.0);
        Streams::write($command, "CONNECT evil.example:443 HTTP/1.1\r\n\r\n");
        self::assertNotSame([], Streams::readable([$command], 5.0), 'The proxy did not answer.');
        $answer = Streams::read($command, 65536);
        $proxy->signal(\SIGTERM);
        $proxy->wait();

        self::assertStringStartsWith("HTTP/1.1 403 Forbidden\r\n", $answer);
        self::assertSame('{"host":"evil.example","port":443,"decision":"not_listed"}'."\n", file_get_contents(self::LOG));
        self::assertSame(Command::SUCCESS, $proxy->getExitCode(), $proxy->getErrorOutput());
    }

    public function testAnAddressOnAnotherInterfaceIsRefused(): void
    {
        $tester = $this->tester();

        $exitCode = $tester->execute(['--listen' => '0.0.0.0:8080', '--log' => self::LOG]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('"0.0.0.0:8080" is neither unix:///absolute/path nor 127.0.0.1:<port>.', $tester->getDisplay());
    }

    public function testAnAllowedHostMustBeAHostAndPort(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(['--listen' => '127.0.0.1:0', '--log' => self::LOG, '--allow' => ['*.github.io:443']]));
        self::assertStringContainsString('--allow: "*.github.io" is not a host name.', $tester->getDisplay());
    }

    public function testTheLogFileIsRequired(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(['--listen' => '127.0.0.1:0']));
        self::assertStringContainsString('network-proxy needs --listen and --log.', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find('network-proxy'));
    }
}
