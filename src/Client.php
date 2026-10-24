<?php

declare(strict_types=1);

namespace CaptchaNo;

use CaptchaNo\Exceptions\CaptchaNoException;
use CaptchaNo\Exceptions\ConnectionException;
use CaptchaNo\Exceptions\InvalidApiKeyException;
use CaptchaNo\Exceptions\ProxyRequiredException;
use CaptchaNo\Exceptions\TaskNotFoundException;
use CaptchaNo\Exceptions\TimeoutException;
use CaptchaNo\Exceptions\UnsupportedTypeException;
use CaptchaNo\Http\CurlTransport;
use CaptchaNo\Http\Transport;
use CaptchaNo\Models\Balance;
use CaptchaNo\Models\Task;

/**
 * CaptchaNo API client.
 *
 *     $captchano = new CaptchaNo\Client(); // reads CAPTCHANO_API_KEY
 *     $task = $captchano->recaptchaV2('https://example.com/login', '6Lc...');
 *     echo $task->token();
 *
 * API reference: https://captchano.com/captcha-solver-api
 */
final class Client
{
    public const VERSION = '1.0.0';
    public const DEFAULT_BASE_URL = 'https://api.captchano.com';
    /** Longest ?wait= the API honours, in seconds. */
    public const MAX_WAIT = 120;

    private readonly ?string $apiKey;
    private readonly string $baseUrl;
    private readonly Transport $transport;
    private readonly int $wait;
    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    /**
     * @param ?string $apiKey Key starting with cn-. Default: getenv('CAPTCHANO_API_KEY')
     * @param float $timeout Per-request timeout in seconds; blocking solves add the wait
     * @param int $wait Seconds POST /v1/solve blocks server-side (0-120, 0 = poll only)
     * @param float $pollInterval Seconds between GET /v1/task polls
     * @param float $maxWait Cap in seconds on one solve() / waitFor() call (0 = no cap)
     * @param int $maxRetries Retries for HTTP 429
     * @param ?Transport $transport Default CurlTransport; see Http\Psr18Transport
     * @param ?\Closure(float): void $sleep Injected for tests
     */
    public function __construct(
        ?string $apiKey = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly float $timeout = 30.0,
        int $wait = 60,
        private readonly float $pollInterval = 5.0,
        private readonly float $maxWait = 180.0,
        private readonly int $maxRetries = 2,
        ?Transport $transport = null,
        ?\Closure $sleep = null,
    ) {
        $key = $apiKey ?? (getenv('CAPTCHANO_API_KEY') ?: null);
        $key = $key === null ? null : trim($key);
        $this->apiKey = $key === '' ? null : $key;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport ?? new CurlTransport();
        $this->wait = max(0, min(self::MAX_WAIT, $wait));
        $this->sleep = $sleep ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) ($seconds * 1_000_000));
            }
        };
    }

    // ---------------------------------------------------------------- core calls

    /**
     * Submits a challenge and blocks until it is ready.
     *
     * @param array<string, mixed> $request Body of POST /v1/solve, e.g. ['type' => 'turnstile', 'url' => ..., 'sitekey' => ...]
     * @throws CaptchaNoException typed per error code; a failed task is thrown with $e->task set
     * @throws TimeoutException when still solving after maxWait ($e->taskId lets you resume)
     */
    public function solve(array $request): Task
    {
        $start = microtime(true);
        $wait = $this->maxWait > 0 ? min($this->wait, (int) $this->maxWait) : $this->wait;
        $task = $this->post($request, $wait);
        if ($task->isDone()) {
            return $task;
        }

        return $this->poll($task, $start, true);
    }

    /**
     * Submits a challenge without blocking and returns the queued task.
     *
     * @param array<string, mixed> $request
     */
    public function submit(array $request): Task
    {
        return $this->post($request, 0);
    }

    /** Fetches a task once. Solutions stay retrievable for ten minutes. */
    public function getTask(string $id): Task
    {
        if ($id === '') {
            throw new TaskNotFoundException('Task id is empty');
        }
        $response = $this->request('GET', '/v1/task/' . rawurlencode($id));

        return $this->taskFrom($response);
    }

    /** Polls a task until it is ready or failed. Transient errors while polling are retried. */
    public function waitFor(string $id): Task
    {
        return $this->poll(new Task($id, Task::SOLVING), microtime(true), false);
    }

    /** Remaining prepaid credit. */
    public function balance(): Balance
    {
        $response = $this->request('GET', '/v1/balance');
        $data = $response['data'];
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw $this->errorFrom($response);
        }
        if (!is_array($data) || !isset($data['balance'], $data['currency']) || !is_scalar($data['balance'])) {
            throw new CaptchaNoException('Unexpected balance response', 'http_error', $response['status'], body: $data);
        }

        return new Balance((string) $data['balance'], (string) $data['currency']);
    }

    // ---------------------------------------------------------------- per-type helpers

    /**
     * reCAPTCHA v2 checkbox or invisible widget. Set $enterprise for Enterprise checkbox widgets.
     *
     * @param ?string $proxy scheme://user:pass@host:port — the IP that will submit the token
     */
    public function recaptchaV2(
        string $url,
        string $sitekey,
        bool $invisible = false,
        bool $enterprise = false,
        ?string $proxy = null,
        ?string $userAgent = null,
    ): Task {
        return $this->solve(self::compact([
            'type' => $enterprise ? ChallengeType::RECAPTCHA_ENTERPRISE : ChallengeType::RECAPTCHA_V2,
            'url' => $url,
            'sitekey' => $sitekey,
            'invisible' => $invisible ?: null,
            'proxy' => $proxy,
            'userAgent' => $userAgent,
        ]));
    }

    /** Score-based reCAPTCHA v3. Send the action the page itself uses. */
    public function recaptchaV3(
        string $url,
        string $sitekey,
        ?string $action = null,
        ?float $minScore = null,
        ?string $proxy = null,
        ?string $userAgent = null,
    ): Task {
        return $this->solve(self::compact([
            'type' => ChallengeType::RECAPTCHA_V3,
            'url' => $url,
            'sitekey' => $sitekey,
            'action' => $action,
            'minScore' => $minScore,
            'proxy' => $proxy,
            'userAgent' => $userAgent,
        ]));
    }

    /** Cloudflare Turnstile. cData and pageData are only needed on Cloudflare challenge pages. */
    public function turnstile(
        string $url,
        string $sitekey,
        ?string $action = null,
        ?string $cData = null,
        ?string $pageData = null,
        ?string $proxy = null,
        ?string $userAgent = null,
    ): Task {
        return $this->solve(self::compact([
            'type' => ChallengeType::TURNSTILE,
            'url' => $url,
            'sitekey' => $sitekey,
            'action' => $action,
            'cData' => $cData,
            'pageData' => $pageData,
            'proxy' => $proxy,
            'userAgent' => $userAgent,
        ]));
    }

    /**
     * DataDome. The cookie to set is in $task->solution->cookie; replay
     * $task->solution->userAgent through the same proxy.
     *
     * @param string $challengeUrl src of the DataDome challenge iframe
     * @param string $proxy scheme://user:pass@host:port (required)
     */
    public function dataDome(string $url, string $challengeUrl, string $proxy, ?string $userAgent = null): Task
    {
        if ($proxy === '') {
            throw new ProxyRequiredException('DataDome requires a proxy');
        }

        return $this->solve(self::compact([
            'type' => ChallengeType::DATADOME,
            'url' => $url,
            'challengeUrl' => $challengeUrl,
            'proxy' => $proxy,
            'userAgent' => $userAgent,
        ]));
    }

    /** Image (text) captcha from raw image bytes. The answer is in $task->solution->text. */
    public function image(string $imageBytes): Task
    {
        if ($imageBytes === '') {
            throw new UnsupportedTypeException('The image is empty');
        }

        return $this->solve(['type' => ChallengeType::IMAGE, 'body' => base64_encode($imageBytes)]);
    }

    // ---------------------------------------------------------------- internals

    /** @param array<string, mixed> $request */
    private function post(array $request, int $wait): Task
    {
        $path = '/v1/solve' . ($wait > 0 ? '?wait=' . $wait : '');
        $response = $this->request('POST', $path, $request, $wait);

        return $this->taskFrom($response);
    }

    private function poll(Task $task, float $start, bool $sleepFirst): Task
    {
        for (;;) {
            if ($sleepFirst) {
                $remaining = $this->maxWait > 0 ? $this->maxWait - (microtime(true) - $start) : $this->pollInterval;
                if ($remaining <= 0) {
                    throw $this->timeout($task);
                }
                ($this->sleep)(min($this->pollInterval, $remaining));
            }
            $sleepFirst = true;
            try {
                $task = $this->getTask($task->id);
            } catch (ConnectionException $e) {
                continue;
            } catch (CaptchaNoException $e) {
                if ($e->task !== null || !self::transient($e)) {
                    throw $e;
                }
                continue;
            }
            if ($task->isDone()) {
                return $task;
            }
        }
    }

    private function timeout(Task $task): TimeoutException
    {
        return new TimeoutException(
            "Task {$task->id} is still solving after {$this->maxWait}s; resume with waitFor()",
            taskId: $task->id,
            task: $task,
        );
    }

    private static function transient(CaptchaNoException $e): bool
    {
        return in_array($e->errorCode, ['rate_limited', 'capacity_unavailable'], true)
            || ($e->status !== null && $e->status >= 500);
    }

    /**
     * @param array{status: int, headers: array<string, string>, data: mixed} $response
     */
    private function taskFrom(array $response): Task
    {
        $status = $response['status'];
        $data = $response['data'];
        if (Task::looksLikeTask($data)) {
            /** @var array<string, mixed> $data */
            $task = Task::fromArray($data);
            if ($task->status === Task::FAILED) {
                throw CaptchaNoException::forCode(
                    $task->errorCode ?? 'unsolvable',
                    $task->errorMessage ?? 'The task failed',
                    $status,
                    $task->id,
                    $task,
                    $data,
                );
            }
            if ($status >= 200 && $status < 300) {
                return $task;
            }
        }
        if ($status >= 200 && $status < 300) {
            throw new CaptchaNoException('Unexpected response body', 'http_error', $status, body: $data);
        }

        throw $this->errorFrom($response);
    }

    /**
     * @param array{status: int, headers: array<string, string>, data: mixed} $response
     */
    private function errorFrom(array $response): CaptchaNoException
    {
        $status = $response['status'];
        $data = $response['data'];
        $code = null;
        $message = null;
        if (is_array($data)) {
            $error = $data['error'] ?? null;
            if (is_array($error)) {
                $code = isset($error['code']) && is_string($error['code']) ? $error['code'] : null;
                $message = isset($error['message']) && is_string($error['message']) ? $error['message'] : null;
            } elseif (is_string($error)) {
                $code = $error;
            }
            if ($message === null && isset($data['message']) && is_string($data['message'])) {
                $message = $data['message'];
            }
        }
        $retryAfter = $status === 429 ? self::retryAfter($response['headers']) : null;

        return CaptchaNoException::forCode(
            $code ?? CaptchaNoException::codeForStatus($status),
            $message ?? "CaptchaNo API returned HTTP $status",
            $status,
            is_array($data) && isset($data['id']) && is_string($data['id']) ? $data['id'] : null,
            null,
            $data,
            $retryAfter,
        );
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{status: int, headers: array<string, string>, data: mixed}
     */
    private function request(string $method, string $path, ?array $body = null, int $extraTimeout = 0): array
    {
        if ($this->apiKey === null) {
            throw new InvalidApiKeyException(
                'No API key: pass apiKey: to the client or set CAPTCHANO_API_KEY. Create one at https://captchano.com/dashboard/keys',
            );
        }
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'captchano-php/' . self::VERSION,
        ];
        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        for ($attempt = 0;; $attempt++) {
            $response = $this->transport->send($method, $this->baseUrl . $path, $headers, $payload, $this->timeout + $extraTimeout);
            if ($response['status'] === 429 && $attempt < $this->maxRetries) {
                $wait = self::retryAfter($response['headers']);
                ($this->sleep)($wait !== null && $wait > 0 ? $wait : 0.5 * (2 ** $attempt));
                continue;
            }
            $data = null;
            if ($response['body'] !== '') {
                try {
                    $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $data = null;
                }
            }

            return ['status' => $response['status'], 'headers' => $response['headers'], 'data' => $data];
        }
    }

    /** @param array<string, string> $headers */
    private static function retryAfter(array $headers): ?float
    {
        $value = $headers['retry-after'] ?? null;

        return $value !== null && is_numeric($value) ? min(30.0, max(0.0, (float) $value)) : null;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function compact(array $fields): array
    {
        return array_filter($fields, static fn ($v): bool => $v !== null && $v !== '');
    }
}
