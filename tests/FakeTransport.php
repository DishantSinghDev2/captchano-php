<?php

declare(strict_types=1);

namespace CaptchaNo\Tests;

use CaptchaNo\Http\Transport;

/** Replays queued responses and records every request. */
final class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    /** @param list<array{status: int, headers?: array<string, string>, body: string}|\Throwable> $queue */
    public function __construct(private array $queue)
    {
    }

    public static function json(int $status, mixed $body, array $headers = []): array
    {
        return ['status' => $status, 'headers' => $headers, 'body' => json_encode($body)];
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        $next = array_shift($this->queue) ?? throw new \LogicException('No more queued responses');
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return ['status' => $next['status'], 'headers' => $next['headers'] ?? [], 'body' => $next['body']];
    }
}
