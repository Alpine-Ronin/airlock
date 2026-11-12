<?php

declare(strict_types=1);

namespace App\Update;

/**
 * Replaces the running Airlock binary with a release's, as install.sh installs it: the binary for this machine,
 * checked against the release's SHA256SUMS and, with the GitHub CLI logged in, its attestation. An update is never
 * counted as an install.
 */
final readonly class BinaryUpdater
{
    public function __construct(
        private Releases $releases,
        private AttestationCheck $attestationCheck,
    ) {
    }

    /**
     * @throws UpdateFailedException
     */
    public function toLatest(string $binaryPath, string $installedVersion): Update
    {
        return $this->toVersion($binaryPath, $installedVersion, $this->releases->latestTag());
    }

    /**
     * @param string $tag a release tag, such as v1.2.3
     *
     * @throws UpdateFailedException
     */
    public function toVersion(string $binaryPath, string $installedVersion, string $tag): Update
    {
        if ($tag === $installedVersion) {
            return Update::alreadyInstalled($installedVersion);
        }
        $directory = \dirname($binaryPath);
        if (!is_writable($directory)) {
            throw new UpdateFailedException(\sprintf('Airlock cannot write to %s, where %s is: run airlock update as the user who installed it.', $directory, basename($binaryPath)));
        }

        $binary = $this->binaryName();
        $download = $this->temporaryFile($directory);
        try {
            $this->releases->download($tag, $binary, $download);
            $this->assertMatchesChecksum($download, $binary, $tag);
            $attestation = $this->attestationCheck->check($download, $tag);
            $this->replace($binaryPath, $download);
        } finally {
            if (is_file($download)) {
                unlink($download);
            }
        }

        return new Update($installedVersion, $tag, $attestation);
    }

    /**
     * @throws UpdateFailedException when no binary is published for this machine
     */
    private function binaryName(): string
    {
        $system = match (\PHP_OS_FAMILY) {
            'Darwin' => 'macos',
            'Linux' => 'linux',
            default => throw new UpdateFailedException(\sprintf('No Airlock binary for %s: Airlock runs on macOS and Linux.', \PHP_OS_FAMILY)),
        };
        $architecture = match (php_uname('m')) {
            'x86_64', 'amd64' => 'x86_64',
            'arm64', 'aarch64' => 'aarch64',
            default => throw new UpdateFailedException(\sprintf('No Airlock binary for the %s architecture.', php_uname('m'))),
        };
        if ('macos' === $system && 'x86_64' === $architecture) {
            throw new UpdateFailedException('No Airlock binary for Intel Macs: run Airlock with Docker or PHP 8.5, see the README.');
        }

        return \sprintf('airlock-%s-%s', $system, $architecture);
    }

    private function assertMatchesChecksum(string $download, string $binary, string $tag): void
    {
        $sums = $this->temporaryFile(sys_get_temp_dir());
        try {
            $this->releases->download($tag, 'SHA256SUMS', $sums);
            $expected = $this->checksumOf($binary, (string) file_get_contents($sums));
        } finally {
            unlink($sums);
        }
        if (null === $expected) {
            throw new UpdateFailedException(\sprintf('The SHA256SUMS of %s lists no %s.', $tag, $binary));
        }
        if (!hash_equals($expected, (string) hash_file('sha256', $download))) {
            throw new UpdateFailedException(\sprintf('%s of %s does not match its checksum: not installed.', $binary, $tag));
        }
    }

    private function checksumOf(string $binary, string $sums): ?string
    {
        foreach (explode("\n", $sums) as $line) {
            $fields = preg_split('/\s+/', trim($line));
            if (false === $fields || 2 !== \count($fields)) {
                continue;
            }
            [$checksum, $name] = $fields;
            if ($binary === ltrim($name, '*')) {
                return strtolower($checksum);
            }
        }

        return null;
    }

    /**
     * A rename on the same filesystem: the binary is either the old one or the new one, and a running Airlock keeps
     * the old one open until it exits.
     */
    private function replace(string $binaryPath, string $download): void
    {
        if (!chmod($download, 0o755) || !rename($download, $binaryPath)) {
            throw new UpdateFailedException(\sprintf('Could not replace %s.', $binaryPath));
        }
    }

    private function temporaryFile(string $directory): string
    {
        $path = tempnam($directory, '.airlock-update-');
        if (false === $path || \dirname($path) !== realpath($directory)) {
            throw new UpdateFailedException(\sprintf('Could not create a temporary file in %s.', $directory));
        }

        return $path;
    }
}
