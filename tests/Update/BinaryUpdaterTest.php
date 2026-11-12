<?php

declare(strict_types=1);

namespace App\Tests\Update;

use App\Update\Attestation;
use App\Update\AttestationCheck;
use App\Update\BinaryUpdater;
use App\Update\Releases;
use App\Update\UpdateFailedException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BinaryUpdaterTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../var/test-binary-updater';

    private const string BINARY = self::ROOT.'/bin/airlock';

    private const array TARGETS = ['linux-x86_64', 'linux-aarch64', 'macos-aarch64'];

    /** @var list<string> */
    private array $requestedUrls = [];

    #[\Override]
    protected function setUp(): void
    {
        $filesystem = new Filesystem();
        $filesystem->remove(self::ROOT);
        $filesystem->dumpFile(self::BINARY, 'old');
        $this->requestedUrls = [];
    }

    public function testTheLatestReleaseIsCheckedAndPutBesideTheBinary(): void
    {
        $update = $this->updater($this->release('v2.0.0', 'new'))->toLatest(self::BINARY, 'v1.0.0');

        self::assertSame(['v1.0.0', 'v2.0.0', Attestation::Verified], [$update->fromVersion, $update->toVersion, $update->attestation]);
        self::assertNotNull($update->checkedBinary);
        self::assertSame('new', file_get_contents($update->checkedBinary));
        self::assertTrue(is_executable($update->checkedBinary));
        self::assertSame(realpath(\dirname(self::BINARY)), \dirname($update->checkedBinary), 'beside the binary, so that a rename replaces it');
        self::assertSame('old', file_get_contents(self::BINARY), 'the running binary is replaced only once nothing more of it runs');
    }

    public function testTheInstalledVersionIsNotDownloadedAgain(): void
    {
        $update = $this->updater($this->release('v1.0.0', 'new'))->toLatest(self::BINARY, 'v1.0.0');

        self::assertNull($update->checkedBinary);
        self::assertSame(['https://api.github.com/repos/hbenabdallah/airlock/releases/latest'], $this->requestedUrls);
    }

    public function testAGivenVersionIsInstalledFromItsRelease(): void
    {
        $update = $this->updater($this->release('v0.9.0', 'older'))->toVersion(self::BINARY, 'v1.0.0', 'v0.9.0');

        self::assertNotNull($update->checkedBinary);
        self::assertSame('older', file_get_contents($update->checkedBinary));
        self::assertNotContains('https://api.github.com/repos/hbenabdallah/airlock/releases/latest', $this->requestedUrls);
    }

    public function testABinaryThatDoesNotMatchItsChecksumIsRefused(): void
    {
        $responses = $this->release('v2.0.0', 'new');
        $responses = (static fn (string $method, string $url): MockResponse => str_ends_with($url, '/SHA256SUMS') ? new MockResponse(self::sums('v2.0.0', 'other')) : $responses($method, $url));

        $this->expectRefusal($responses, 'does not match its checksum');
    }

    public function testABinaryWithoutAttestationIsRefused(): void
    {
        $refusing = new class implements AttestationCheck {
            #[\Override]
            public function check(string $binaryPath, string $tag): Attestation
            {
                throw new UpdateFailedException('no attestation');
            }
        };

        try {
            $this->updater($this->release('v2.0.0', 'new'), $refusing)->toLatest(self::BINARY, 'v1.0.0');
            self::fail('The update went through.');
        } catch (UpdateFailedException $e) {
            self::assertSame('no attestation', $e->getMessage());
        }
        self::assertSame('old', file_get_contents(self::BINARY));
    }

    public function testAReleaseThatDoesNotExistIsRefused(): void
    {
        $this->expectRefusal(static fn (): MockResponse => new MockResponse('Not Found', ['http_code' => 404]), 'GitHub answered 404', 'v9.9.9');
    }

    /**
     * @param \Closure(string, string): MockResponse $responses
     */
    private function expectRefusal(\Closure $responses, string $message, ?string $tag = null): void
    {
        $updater = $this->updater($responses);
        try {
            null === $tag ? $updater->toLatest(self::BINARY, 'v1.0.0') : $updater->toVersion(self::BINARY, 'v1.0.0', $tag);
            self::fail('The update went through.');
        } catch (UpdateFailedException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
        self::assertSame('old', file_get_contents(self::BINARY), 'the binary is left as it was');
        self::assertSame([self::BINARY], glob(self::ROOT.'/bin/{,.}*airlock*', \GLOB_BRACE), 'no temporary file is left');
    }

    /**
     * @return \Closure(string, string): MockResponse
     */
    private function release(string $tag, string $binary): \Closure
    {
        return static function (string $method, string $url) use ($tag, $binary): MockResponse {
            $download = \sprintf('https://github.com/hbenabdallah/airlock/releases/download/%s/', $tag);

            return match (true) {
                str_ends_with($url, '/releases/latest') => new MockResponse((string) json_encode(['tag_name' => $tag])),
                $url === $download.'SHA256SUMS' => new MockResponse(self::sums($tag, $binary)),
                str_starts_with($url, $download.'airlock-') => new MockResponse($binary),
                default => new MockResponse('Not Found', ['http_code' => 404]),
            };
        };
    }

    private static function sums(string $tag, string $binary): string
    {
        $sums = '';
        foreach (self::TARGETS as $target) {
            $sums .= \sprintf("%s  airlock-%s\n", hash('sha256', $binary), $target);
        }

        return $sums.hash('sha256', $tag)."  airlock.cdx.json\n";
    }

    /**
     * @param \Closure(string, string): MockResponse $responses
     */
    private function updater(\Closure $responses, ?AttestationCheck $attestationCheck = null): BinaryUpdater
    {
        $httpClient = new MockHttpClient(function (string $method, string $url) use ($responses): MockResponse {
            $this->requestedUrls[] = $url;

            return $responses($method, $url);
        });
        $verifying = new class implements AttestationCheck {
            #[\Override]
            public function check(string $binaryPath, string $tag): Attestation
            {
                return Attestation::Verified;
            }
        };

        return new BinaryUpdater(new Releases($httpClient), $attestationCheck ?? $verifying);
    }
}
