<?php

declare(strict_types=1);

namespace CaptchaNo\Tests;

use CaptchaNo\Client;
use CaptchaNo\Exceptions\CapacityUnavailableException;
use CaptchaNo\Exceptions\CaptchaNoException;
use CaptchaNo\Exceptions\ConnectionException;
use CaptchaNo\Exceptions\InsufficientBalanceException;
use CaptchaNo\Exceptions\InvalidApiKeyException;
use CaptchaNo\Exceptions\InvalidSitekeyException;
use CaptchaNo\Exceptions\ProxyRequiredException;
use CaptchaNo\Exceptions\ProxyUnreachableException;
use CaptchaNo\Exceptions\RateLimitedException;
use CaptchaNo\Exceptions\ScoreNotMetException;
use CaptchaNo\Exceptions\TaskNotFoundException;
use CaptchaNo\Exceptions\TimeoutException;
use CaptchaNo\Exceptions\UnsolvableException;
use CaptchaNo\Exceptions\UnsupportedTypeException;
use CaptchaNo\Exceptions\UpstreamErrorException;
use CaptchaNo\Http\Psr18Transport;
use CaptchaNo\Models\Task;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class ClientTest extends TestCase
{
    private const KEY = 'cn-test-key';

    /** @var list<float> */
    private array $sleeps = [];

    private function client(FakeTransport $transport, array $opts = []): Client
    {
        return new Client(...$opts + [
            'apiKey' => self::KEY,
            'baseUrl' => 'https://api.test',
            'transport' => $transport,
            'sleep' => function (float $s): void {
                $this->sleeps[] = $s;
            },
        ]);
    }

    private static function ready(string $id, string $type, array $solution): array
    {
        return FakeTransport::json(200, [
            'id' => $id, 'status' => 'ready', 'type' => $type, 'solution' => $solution,
            'cost' => '0.0010', 'billedFrom' => 'balance', 'solveTime' => 9.5, 'createdAt' => 1767225600000,
        ]);
    }

    private static function body(FakeTransport $t, int $i): array
    {
        return json_decode((string) $t->requests[$i]['body'], true);
    }

    public function testRecaptchaV2InOneCall(): void
    {
        $t = new FakeTransport([self::ready('slv_1', 'recaptcha_v2', ['token' => 'tok-123', 'userAgent' => 'UA/1'])]);
        $task = $this->client($t)->recaptchaV2('https://example.com/login', '6Lsitekey', proxy: 'http://u:p@h:1');

        self::assertSame('tok-123', $task->token());
        self::assertSame('UA/1', $task->solution?->userAgent);
        self::assertSame('0.0010', $task->cost);
        self::assertSame(9.5, $task->solveTime);
        self::assertSame(1767225600000, $task->createdAt);

        $r = $t->requests[0];
        self::assertSame('POST', $r['method']);
        self::assertSame('https://api.test/v1/solve?wait=60', $r['url']);
        self::assertSame('Bearer ' . self::KEY, $r['headers']['Authorization']);
        self::assertSame('captchano-php/' . Client::VERSION, $r['headers']['User-Agent']);
        self::assertSame(
            ['type' => 'recaptcha_v2', 'url' => 'https://example.com/login', 'sitekey' => '6Lsitekey', 'proxy' => 'http://u:p@h:1'],
            self::body($t, 0),
        );
    }

    public function testPollsAfter202AndRetriesTransientErrors(): void
    {
        $t = new FakeTransport([
            FakeTransport::json(202, ['id' => 'slv_2', 'status' => 'solving', 'type' => 'turnstile']),
            FakeTransport::json(200, ['id' => 'slv_2', 'status' => 'queued', 'type' => 'turnstile']),
            FakeTransport::json(503, ['error' => ['code' => 'capacity_unavailable', 'message' => 'busy']]),
            new ConnectionException('reset'),
            self::ready('slv_2', 'turnstile', ['token' => 'ts-tok']),
        ]);
        $task = $this->client($t, ['pollInterval' => 2.0])->turnstile('https://example.com', '0x4AAAAAAAAAAA', cData: 'cd');

        self::assertSame('ts-tok', $task->token());
        self::assertCount(5, $t->requests);
        self::assertSame('GET', $t->requests[1]['method']);
        self::assertSame('https://api.test/v1/task/slv_2', $t->requests[1]['url']);
        self::assertSame('cd', self::body($t, 0)['cData']);
        self::assertSame([2.0, 2.0, 2.0, 2.0], $this->sleeps);
    }

    public function testFailedTaskThrowsTypedExceptionWithTask(): void
    {
        $t = new FakeTransport([FakeTransport::json(422, [
            'id' => 'slv_3', 'status' => 'failed', 'type' => 'recaptcha_v3',
            'error' => ['code' => 'score_not_met', 'message' => 'low'],
        ])]);
        try {
            $this->client($t)->recaptchaV3('https://e.com', '6L', action: 'login', minScore: 0.7);
            self::fail('expected exception');
        } catch (ScoreNotMetException $e) {
            self::assertSame('score_not_met', $e->errorCode);
            self::assertSame('low', $e->apiMessage);
            self::assertSame(422, $e->status);
            self::assertSame('slv_3', $e->taskId);
            self::assertSame(Task::FAILED, $e->task?->status);
            self::assertTrue($e->isRetryable());
        }
        self::assertSame(['type' => 'recaptcha_v3', 'url' => 'https://e.com', 'sitekey' => '6L', 'action' => 'login', 'minScore' => 0.7], self::body($t, 0));
    }

    /** @return iterable<string, array{array, class-string<CaptchaNoException>}> */
    public static function errorCases(): iterable
    {
        $env = static fn (int $s, string $code): array => FakeTransport::json($s, ['error' => ['code' => $code, 'message' => 'm']]);
        $failed = static fn (int $s, string $code): array => FakeTransport::json($s, ['id' => 'slv_x', 'status' => 'failed', 'type' => 'image', 'error' => ['code' => $code, 'message' => 'm']]);

        yield 'invalid_api_key' => [$env(401, 'invalid_api_key'), InvalidApiKeyException::class];
        yield 'unsupported_type' => [$env(400, 'unsupported_type'), UnsupportedTypeException::class];
        yield 'invalid_sitekey' => [$env(400, 'invalid_sitekey'), InvalidSitekeyException::class];
        yield 'proxy_unreachable' => [$env(400, 'proxy_unreachable'), ProxyUnreachableException::class];
        yield 'insufficient_balance' => [$failed(402, 'insufficient_balance'), InsufficientBalanceException::class];
        yield 'proxy_required' => [$failed(422, 'proxy_required'), ProxyRequiredException::class];
        yield 'unsolvable' => [$failed(422, 'unsolvable'), UnsolvableException::class];
        yield 'upstream_error' => [$failed(422, 'upstream_error'), UpstreamErrorException::class];
        yield 'capacity_unavailable' => [$env(503, 'capacity_unavailable'), CapacityUnavailableException::class];
        yield '503 without body' => [['status' => 503, 'body' => 'oops'], CapacityUnavailableException::class];
        yield '401 without body' => [['status' => 401, 'body' => ''], InvalidApiKeyException::class];
    }

    /** @param class-string<CaptchaNoException> $class */
    #[DataProvider('errorCases')]
    public function testErrorCodesMapToExceptions(array $response, string $class): void
    {
        $this->expectException($class);
        $this->client(new FakeTransport([$response]))->submit(['type' => 'image', 'body' => 'aGk=']);
    }

    public function testRateLimitRetriedWithRetryAfter(): void
    {
        $t = new FakeTransport([
            FakeTransport::json(429, ['error' => ['code' => 'rate_limited', 'message' => 'slow']], ['retry-after' => '3']),
            FakeTransport::json(200, ['balance' => '4.2500', 'currency' => 'USD']),
        ]);
        $balance = $this->client($t)->balance();

        self::assertSame('4.2500', $balance->balance);
        self::assertSame('USD', $balance->currency);
        self::assertSame(4.25, $balance->toFloat());
        self::assertSame([3.0], $this->sleeps);
        self::assertSame('https://api.test/v1/balance', $t->requests[1]['url']);
    }

    public function testRateLimitExhausted(): void
    {
        $limited = FakeTransport::json(429, ['error' => ['code' => 'rate_limited', 'message' => 'slow']], ['retry-after' => '1']);
        try {
            $this->client(new FakeTransport([$limited, $limited, $limited]))->balance();
            self::fail('expected exception');
        } catch (RateLimitedException $e) {
            self::assertSame(1.0, $e->retryAfter);
        }
    }

    public function testGetTaskNotFoundAndEncodesId(): void
    {
        $t = new FakeTransport([FakeTransport::json(404, ['error' => ['code' => 'task_not_found', 'message' => 'No task with that id.']])]);
        try {
            $this->client($t)->getTask('slv/../x');
            self::fail('expected exception');
        } catch (TaskNotFoundException $e) {
            self::assertSame(404, $e->status);
        }
        self::assertSame('https://api.test/v1/task/slv%2F..%2Fx', $t->requests[0]['url']);
    }

    public function testWaitForTimesOut(): void
    {
        $solving = FakeTransport::json(200, ['id' => 'slv_8', 'status' => 'solving', 'type' => 'image']);
        $t = new FakeTransport(array_fill(0, 50, $solving));
        $client = new Client(apiKey: self::KEY, baseUrl: 'https://api.test', pollInterval: 0.01, maxWait: 0.05, transport: $t);
        try {
            $client->waitFor('slv_8');
            self::fail('expected exception');
        } catch (TimeoutException $e) {
            self::assertSame('slv_8', $e->taskId);
            self::assertSame('timeout', $e->errorCode);
        }
    }

    public function testImageAndDataDome(): void
    {
        $t = new FakeTransport([
            self::ready('slv_10', 'image', ['text' => 'x7k2']),
            self::ready('slv_11', 'datadome', ['cookie' => 'datadome=abc', 'userAgent' => 'UA']),
        ]);
        $client = $this->client($t);

        self::assertSame('x7k2', $client->image('png-bytes')->solution?->text);
        self::assertSame(['type' => 'image', 'body' => base64_encode('png-bytes')], self::body($t, 0));

        $task = $client->dataDome('https://e.com', 'https://geo.captcha-delivery.com/x', 'http://u:p@h:1');
        self::assertSame('datadome=abc', $task->solution?->cookie);
        self::assertSame('https://geo.captcha-delivery.com/x', self::body($t, 1)['challengeUrl']);

        $this->expectException(ProxyRequiredException::class);
        $client->dataDome('https://e.com', 'https://geo.captcha-delivery.com/x', '');
    }

    public function testEnterpriseAndWaitOptions(): void
    {
        $t = new FakeTransport([
            self::ready('slv_12', 'recaptcha_enterprise', ['token' => 't']),
            FakeTransport::json(202, ['id' => 'slv_13', 'status' => 'solving', 'type' => 'image']),
        ]);
        $this->client($t, ['wait' => 500])->recaptchaV2('u', '6L', invisible: true, enterprise: true);
        self::assertSame('https://api.test/v1/solve?wait=120', $t->requests[0]['url']);
        self::assertSame(['type' => 'recaptcha_enterprise', 'url' => 'u', 'sitekey' => '6L', 'invisible' => true], self::body($t, 0));

        $task = $this->client($t)->submit(['type' => 'image', 'body' => 'x']);
        self::assertSame('https://api.test/v1/solve', $t->requests[1]['url']);
        self::assertFalse($task->isDone());
    }

    public function testMissingKeyAndEnv(): void
    {
        $previous = getenv('CAPTCHANO_API_KEY');
        try {
            putenv('CAPTCHANO_API_KEY');
            try {
                (new Client(transport: new FakeTransport([])))->balance();
                self::fail('expected exception');
            } catch (InvalidApiKeyException $e) {
                self::assertNull($e->status);
            }

            putenv('CAPTCHANO_API_KEY=cn-from-env');
            $t = new FakeTransport([FakeTransport::json(200, ['balance' => 1, 'currency' => 'USD'])]);
            (new Client(transport: $t))->balance();
            self::assertSame('Bearer cn-from-env', $t->requests[0]['headers']['Authorization']);
        } finally {
            putenv($previous === false ? 'CAPTCHANO_API_KEY' : "CAPTCHANO_API_KEY=$previous");
        }
    }

    public function testPsr18Transport(): void
    {
        $http = new class () implements ClientInterface {
            public ?RequestInterface $last = null;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->last = $request;

                return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                    'id' => 'slv_14', 'status' => 'ready', 'type' => 'turnstile', 'solution' => ['token' => 'psr'],
                ]));
            }
        };
        $factory = new Psr17Factory();
        $client = new Client(apiKey: self::KEY, baseUrl: 'https://api.test', transport: new Psr18Transport($http, $factory, $factory));

        self::assertSame('psr', $client->turnstile('https://e.com', '0x4AAAAAAAAAAA')->token());
        self::assertSame('POST', $http->last?->getMethod());
        self::assertSame('Bearer ' . self::KEY, $http->last?->getHeaderLine('Authorization'));
        self::assertSame('turnstile', json_decode((string) $http->last?->getBody(), true)['type']);
    }
}
