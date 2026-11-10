<?php

declare(strict_types=1);

namespace App\Network;

/**
 * The head of a request sent to the proxy: `CONNECT host:port` opens a tunnel (HTTPS), an absolute `http://` URL is
 * forwarded to its host with the URL cut to its path. The forwarded head asks for the connection to close after one
 * response, and keeps no header meant for the proxy.
 */
final readonly class ProxyRequest
{
    /** Headers for the proxy or this one hop, never forwarded. */
    private const array HOP_HEADERS = ['connection', 'keep-alive', 'proxy-connection', 'proxy-authorization', 'te', 'trailer', 'upgrade'];

    private function __construct(
        public ProxyTarget $target,
        public bool $isTunnel,
        public string $headToForward,
    ) {
    }

    /**
     * @param string $head the request line and headers, up to and with the empty line that ends them
     *
     * @throws MalformedProxyRequestException
     */
    public static function fromHead(string $head): self
    {
        $lines = explode("\r\n", substr($head, 0, -4));
        $requestLine = array_shift($lines);
        if (1 !== preg_match('/^(?<method>[A-Z]+) (?<uri>\S+) (?<version>HTTP\/1\.[01])$/', $requestLine, $request)) {
            throw new MalformedProxyRequestException(\sprintf('"%s" is not an HTTP/1 request line.', $requestLine));
        }
        if ('CONNECT' === $request['method']) {
            return new self(ProxyTarget::fromAuthority($request['uri'], defaultPort: null), true, '');
        }
        if (1 !== preg_match('#^http://(?<authority>[^/?\#]+)(?<path>[/?][^\#]*)?$#i', $request['uri'], $url)) {
            throw new MalformedProxyRequestException(\sprintf('"%s" is neither CONNECT nor an absolute http:// URL.', $requestLine));
        }
        $target = ProxyTarget::fromAuthority($url['authority'], defaultPort: 80);
        $path = $url['path'] ?? '';
        if ('' === $path) {
            $path = '/';
        }

        return new self($target, false, self::forwardedHead($request['method'], $path, $request['version'], $lines));
    }

    /**
     * @param list<string> $headers
     */
    private static function forwardedHead(string $method, string $path, string $version, array $headers): string
    {
        $head = \sprintf("%s %s %s\r\n", $method, $path, $version);
        foreach ($headers as $header) {
            $name = strtolower(trim(explode(':', $header, 2)[0]));
            if (!\in_array($name, self::HOP_HEADERS, true)) {
                $head .= $header."\r\n";
            }
        }

        return $head."Connection: close\r\n\r\n";
    }
}
