<?php

declare(strict_types=1);

namespace CaptchaNo\Http;

use CaptchaNo\Exceptions\ConnectionException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Sends requests through any PSR-18 client (Guzzle 7, Symfony HttpClient's Psr18Client, ...).
 *
 *     $http = new GuzzleHttp\Client(['timeout' => 150]);
 *     $factory = new GuzzleHttp\Psr7\HttpFactory();
 *     $transport = new Psr18Transport($http, $factory, $factory);
 *
 * PSR-18 has no per-request timeout, so configure the client's own timeout above the
 * longest ?wait= you use (120 s max) plus some headroom.
 */
final class Psr18Transport implements Transport
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $request = $this->requestFactory->createRequest($method, $url);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request = $request->withBody($this->streamFactory->createStream($body));
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new ConnectionException('Could not reach the CaptchaNo API: ' . $e->getMessage(), previous: $e);
        }

        $responseHeaders = [];
        foreach ($response->getHeaders() as $name => $values) {
            $responseHeaders[strtolower((string) $name)] = implode(', ', $values);
        }

        return ['status' => $response->getStatusCode(), 'headers' => $responseHeaders, 'body' => (string) $response->getBody()];
    }
}
