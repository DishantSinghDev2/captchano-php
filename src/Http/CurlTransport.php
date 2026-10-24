<?php

declare(strict_types=1);

namespace CaptchaNo\Http;

use CaptchaNo\Exceptions\ConnectionException;

/** Default transport, built on ext-curl. */
final class CurlTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ConnectionException('Could not initialise cURL');
        }
        $responseHeaders = [];
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => 10_000,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($ch);
        if ($result === false) {
            $message = curl_error($ch);
            $timedOut = curl_errno($ch) === CURLE_OPERATION_TIMEDOUT;
            throw new ConnectionException($timedOut ? "Request timed out after {$timeout}s" : "Could not reach the CaptchaNo API: $message");
        }

        return ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => $responseHeaders, 'body' => (string) $result];
    }
}
