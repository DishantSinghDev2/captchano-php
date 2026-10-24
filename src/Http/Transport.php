<?php

declare(strict_types=1);

namespace CaptchaNo\Http;

/**
 * Pluggable HTTP layer. The default is {@see CurlTransport}; use {@see Psr18Transport} for any PSR-18 client
 * (Guzzle 7, Symfony HttpClient), or implement this for a framework client
 * or a fake in tests.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string} header names lower-cased
     * @throws \CaptchaNo\Exceptions\ConnectionException when no response was received
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): array;
}
