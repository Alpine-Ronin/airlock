<?php

declare(strict_types=1);

namespace App\Tests\Update;

use App\Update\Attestation;
use App\Update\GitHubCliAttestationCheck;
use App\Update\UpdateFailedException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class GitHubCliAttestationCheckTest extends KernelTestCase
{
    private const string ROOT = __DIR__.'/../../var/test-attestation-check';

    /** Logs each call to gh.log, and fails the calls that start with a line of fail.txt. */
    private const string FAKE_GH = <<<'BASH'
        #!/bin/bash
        directory="$(dirname "$0")"
        printf '%s\n' "$*" >> "$directory/gh.log"
        while IFS= read -r start; do
            if [[ -n "$start" && "$*" == "$start"* ]]; then exit 1; fi
        done < "$directory/fail.txt"
        BASH;

    private Filesystem $filesystem;

    private string $bin;

    private string $path;

    #[\Override]
    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->filesystem->remove(self::ROOT);
        $this->filesystem->dumpFile(self::ROOT.'/bin/gh', self::FAKE_GH);
        $this->filesystem->chmod(self::ROOT.'/bin/gh', 0o755);
        $this->bin = (string) realpath(self::ROOT.'/bin');
        $this->filesystem->dumpFile($this->bin.'/fail.txt', '');
        $this->path = (string) getenv('PATH');
        putenv('PATH='.$this->bin.':'.$this->path);
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->path);
        $this->filesystem->remove(self::ROOT);
        parent::tearDown();
    }

    public function testABinaryIsCheckedAsBuiltByTheReleaseWorkflowFromItsTag(): void
    {
        self::assertSame(Attestation::Verified, $this->check()->check('/tmp/airlock-new', 'v1.2.3'));

        self::assertSame([
            'auth token --hostname github.com',
            'auth status --hostname github.com --active',
            'attestation verify /tmp/airlock-new --hostname github.com --repo alpine-ronin/airlock --cert-identity-regex ^https\://github\.com/alpine\-ronin/airlock/\.github/workflows/binaries\.yaml@refs/tags/v1\.2\.3$',
        ], $this->ghCalls());
    }

    public function testABinaryBuiltBeforeTheMoveToTheOrganisationIsCheckedAsBuiltByTheFormerReleaseWorkflow(): void
    {
        $this->failing('attestation verify /tmp/airlock-new --hostname github.com --repo');

        self::assertSame(Attestation::Verified, $this->check()->check('/tmp/airlock-new', 'v0.6.1'));
        self::assertSame(
            'attestation verify /tmp/airlock-new --hostname github.com --owner hbenabdallah --cert-identity-regex ^https\\://github\\.com/hbenabdallah/airlock/\\.github/workflows/binaries\\.yaml@refs/tags/v0\\.6\\.1$',
            $this->ghCalls()[3],
        );
    }

    public function testWithoutALoginTheBinaryIsNotChecked(): void
    {
        $this->failing('auth token');

        self::assertSame(Attestation::NotChecked, $this->check()->check('/tmp/airlock-new', 'v1.2.3'));
        self::assertSame(['auth token --hostname github.com'], $this->ghCalls());
    }

    public function testALoginThatDoesNotWorkStopsTheUpdateRatherThanSkipTheCheck(): void
    {
        $this->failing('auth status');

        $this->expectException(UpdateFailedException::class);
        $this->expectExceptionMessageIsOrContains('its login to github.com does not work');

        $this->check()->check('/tmp/airlock-new', 'v1.2.3');
    }

    public function testABinaryWithoutAttestationIsRefused(): void
    {
        $this->failing('attestation verify');

        $this->expectException(UpdateFailedException::class);
        $this->expectExceptionMessageIsOrContains('airlock-new has no attestation');

        $this->check()->check('/tmp/airlock-new', 'v1.2.3');
    }

    public function testAReleaseBeforeAttestationsIsNotChecked(): void
    {
        self::assertSame(Attestation::NotChecked, $this->check()->check('/tmp/airlock-new', 'v0.3.3'));
        self::assertSame([], $this->ghCalls());
    }

    private function check(): GitHubCliAttestationCheck
    {
        return self::getContainer()->get(GitHubCliAttestationCheck::class);
    }

    private function failing(string $call): void
    {
        $this->filesystem->dumpFile($this->bin.'/fail.txt', $call."\n");
    }

    /**
     * @return list<string>
     */
    private function ghCalls(): array
    {
        $log = $this->bin.'/gh.log';

        if (!is_file($log)) {
            return [];
        }
        $calls = file($log, \FILE_IGNORE_NEW_LINES);

        return false === $calls ? [] : $calls;
    }
}
