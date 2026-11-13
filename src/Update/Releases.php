<?php

declare(strict_types=1);

namespace App\Update;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Airlock's releases on GitHub: which one is the latest, and their files.
 */
final readonly class Releases
{
    public const string REPOSITORY = 'Alpine-Ronin/airlock';

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * The latest release's tag, such as v1.2.3.
     *
     * @throws UpdateFailedException when GitHub does not say
     */
    public function latestTag(): string
    {
        $url = \sprintf('https://api.github.com/repos/%s/releases/latest', self::REPOSITORY);
        try {
            $release = $this->httpClient->request('GET', $url, ['headers' => ['Accept' => 'application/vnd.github+json']])->toArray();
        } catch (HttpClientException $e) {
            throw new UpdateFailedException(\sprintf('Could not read the latest release from %s: %s', $url, $e->getMessage()), $e->getCode(), previous: $e);
        }
        $tag = $release['tag_name'] ?? null;
        if (!\is_string($tag) || 1 !== preg_match('/^v[0-9]+\.[0-9]+\.[0-9]+$/', $tag)) {
            throw new UpdateFailedException(\sprintf('%s gives no version tag for the latest release.', $url));
        }

        return $tag;
    }

    /**
     * Downloads one file of the release TAG into PATH.
     *
     * @throws UpdateFailedException when it cannot be downloaded
     */
    public function download(string $tag, string $file, string $path): void
    {
        $url = \sprintf('https://github.com/%s/releases/download/%s/%s', self::REPOSITORY, rawurlencode($tag), rawurlencode($file));
        $handle = fopen($path, 'w');
        if (false === $handle) {
            throw new UpdateFailedException(\sprintf('Could not write %s.', $path));
        }
        try {
            $response = $this->httpClient->request('GET', $url);
            if (200 !== $response->getStatusCode()) {
                throw new UpdateFailedException(\sprintf('Could not download %s: GitHub answered %d.', $url, $response->getStatusCode()));
            }
            foreach ($this->httpClient->stream($response) as $chunk) {
                fwrite($handle, $chunk->getContent());
            }
        } catch (HttpClientException $e) {
            throw new UpdateFailedException(\sprintf('Could not download %s: %s', $url, $e->getMessage()), $e->getCode(), previous: $e);
        } finally {
            fclose($handle);
        }
    }
}
