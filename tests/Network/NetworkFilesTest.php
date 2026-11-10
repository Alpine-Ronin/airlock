<?php

declare(strict_types=1);

namespace App\Tests\Network;

use App\Configuration\ConfigDirectory;
use App\Execution\ProjectDirectory;
use App\Network\InvalidNetworkFileException;
use App\Network\NetworkFiles;
use App\Network\NetworkSettings;
use App\Network\ProxyTarget;
use App\Policy\InvalidPolicyException;
use App\Policy\PolicyFiles;
use App\Shell\ShellParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class NetworkFilesTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../var/test-network-files';

    private Filesystem $filesystem;

    private ConfigDirectory $configDirectory;

    private string $project;

    #[\Override]
    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->filesystem->remove(self::ROOT);
        $this->filesystem->mkdir([self::ROOT.'/home', self::ROOT.'/project']);
        $this->configDirectory = new ConfigDirectory((string) realpath(self::ROOT.'/home'));
        $this->project = (string) realpath(self::ROOT.'/project');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->filesystem->remove(self::ROOT);
    }

    public function testWithoutAnyFileTheRegistriesAloneAreReachable(): void
    {
        $settings = $this->settings();

        self::assertTrue($this->allows($settings, 'repo.packagist.org:443'));
        self::assertFalse($this->allows($settings, 'mirror.example.com:443'));
        self::assertSame([], $settings->localPorts);
    }

    public function testTheUsersFileAndTheProjectsFileAddHostsAndLocalPorts(): void
    {
        $this->filesystem->dumpFile($this->configDirectory->path.'/network.yaml', "allow:\n  - Mirror.Example.com\nlocal: [5432]\n");
        $this->filesystem->dumpFile($this->configDirectory->forProject($this->project).'/network.yaml', "allow:\n  - registry.example.com:8443\nlocal: [6379, 5432]\n");

        $settings = $this->settings();

        self::assertTrue($this->allows($settings, 'mirror.example.com:443'), 'A host without a port is allowed on 443.');
        self::assertTrue($this->allows($settings, 'registry.example.com:8443'));
        self::assertFalse($this->allows($settings, 'registry.example.com:443'));
        self::assertTrue($this->allows($settings, 'pypi.org:443'), 'The registries stay.');
        self::assertSame([5432, 6379], $settings->localPorts);
    }

    public function testACommittedPolicyTakesAHostAwayButCannotAddOne(): void
    {
        $this->filesystem->dumpFile($this->configDirectory->path.'/network.yaml', "allow:\n  - mirror.example.com\n");
        $this->filesystem->dumpFile($this->project.'/.airlock.yaml', "refuse_hosts:\n  - api.github.com\n  - mirror.example.com\n");

        $settings = $this->settings();

        self::assertFalse($this->allows($settings, 'api.github.com:443'));
        self::assertFalse($this->allows($settings, 'mirror.example.com:443'));
        self::assertTrue($this->allows($settings, 'codeload.github.com:443'));
    }

    public function testACommittedPolicyCannotAllowAHost(): void
    {
        $this->filesystem->dumpFile($this->project.'/.airlock.yaml', "allow:\n  - evil.example\n");

        $this->expectException(InvalidPolicyException::class);

        $this->settings();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidNetworkFiles(): iterable
    {
        yield 'a wildcard' => ["allow:\n  - '*.github.io'\n", 'no wildcard'];
        yield 'a URL' => ["allow:\n  - https://mirror.example.com/\n", 'Name one host'];
        yield 'a port that is no port' => ["local: [70000]\n", 'local'];
        yield 'the relay\'s port' => ["local: [3128]\n", 'which Airlock\'s relay takes inside the sandbox'];
        yield 'an unknown key' => ["refuse:\n  - make deploy\n", 'refuse'];
        yield 'not YAML' => ["allow: [\n", 'is invalid'];
        yield 'a key given twice' => ["allow: [a.example]\nallow: [b.example]\n", 'Duplicate key'];
    }

    #[DataProvider('invalidNetworkFiles')]
    public function testAnInvalidNetworkFileStopsEverythingUntilItIsFixed(string $content, string $reason): void
    {
        $this->filesystem->dumpFile($this->configDirectory->path.'/network.yaml', $content);

        $this->expectException(InvalidNetworkFileException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($reason, '/').'/');

        $this->settings();
    }

    public function testAPolicyRefusingHostsTwiceIsRefusedRatherThanReadInPart(): void
    {
        $this->filesystem->dumpFile($this->project.'/.airlock.yaml', "refuse_hosts: [api.github.com]\nrefuse_hosts: [pypi.org]\n");

        $this->expectException(InvalidPolicyException::class);
        $this->expectExceptionMessageIsOrContains('Duplicate key');

        $this->settings();
    }

    public function testARefusedHostNamesAHostAlone(): void
    {
        $this->filesystem->dumpFile($this->project.'/.airlock.yaml', "refuse_hosts:\n  - pypi.org:443\n");

        $this->expectException(InvalidPolicyException::class);
        $this->expectExceptionMessageIsOrContains('name the host alone, it is refused on every port');

        $this->settings();
    }

    private function settings(): NetworkSettings
    {
        $projectDirectory = new ProjectDirectory($this->project);

        return new NetworkFiles($this->configDirectory, $projectDirectory, new PolicyFiles($this->configDirectory, $projectDirectory, new ShellParser()))->settings();
    }

    private function allows(NetworkSettings $settings, string $authority): bool
    {
        return $settings->allowedHosts->allows(ProxyTarget::fromAuthority($authority, defaultPort: null));
    }
}
