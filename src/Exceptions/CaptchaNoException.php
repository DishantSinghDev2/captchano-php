<?php

declare(strict_types=1);

namespace CaptchaNo\Exceptions;

use CaptchaNo\Models\Task;

/**
 * Base class for every exception thrown by the SDK.
 *
 * API errors are thrown as one subclass per error code (InvalidSitekeyException,
 * InsufficientBalanceException, ...). Failed tasks carry the {@see Task} in $task.
 */
class CaptchaNoException extends \RuntimeException
{
    /** The API error code this class stands for. Overridden by each subclass. */
    public const CODE = 'error';

    /** Stable machine-readable code, e.g. "invalid_sitekey". */
    public readonly string $errorCode;

    /** The API's own message, without the code prefix and HTTP suffix. */
    public readonly string $apiMessage;

    /**
     * @param ?string $errorCode Defaults to the subclass's CODE
     * @param ?int $status HTTP status, when the error came from a response
     * @param mixed $body Parsed JSON body, when the API sent one
     */
    public function __construct(
        string $message,
        ?string $errorCode = null,
        public readonly ?int $status = null,
        public readonly ?string $taskId = null,
        public readonly ?Task $task = null,
        public readonly mixed $body = null,
        public readonly ?float $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        $errorCode ??= static::CODE;
        $this->errorCode = $errorCode;
        $this->apiMessage = $message;
        parent::__construct(
            $status !== null ? "$errorCode: $message (HTTP $status)" : "$errorCode: $message",
            $status ?? 0,
            $previous,
        );
    }

    /** @var array<string, class-string<CaptchaNoException>> */
    private const MAP = [
        InvalidApiKeyException::CODE => InvalidApiKeyException::class,
        InsufficientBalanceException::CODE => InsufficientBalanceException::class,
        UnsupportedTypeException::CODE => UnsupportedTypeException::class,
        InvalidSitekeyException::CODE => InvalidSitekeyException::class,
        ProxyRequiredException::CODE => ProxyRequiredException::class,
        ProxyUnreachableException::CODE => ProxyUnreachableException::class,
        ScoreNotMetException::CODE => ScoreNotMetException::class,
        UnsolvableException::CODE => UnsolvableException::class,
        CapacityUnavailableException::CODE => CapacityUnavailableException::class,
        RateLimitedException::CODE => RateLimitedException::class,
        TaskNotFoundException::CODE => TaskNotFoundException::class,
        UpstreamErrorException::CODE => UpstreamErrorException::class,
    ];

    /** Builds the typed exception for an API error code. Unknown codes yield this base class. */
    public static function forCode(
        string $code,
        string $message,
        ?int $status = null,
        ?string $taskId = null,
        ?Task $task = null,
        mixed $body = null,
        ?float $retryAfter = null,
    ): self {
        $class = self::MAP[$code] ?? self::class;

        return new $class($message, $code, $status, $taskId, $task, $body, $retryAfter);
    }

    /** Best-effort code when a response carries none. */
    public static function codeForStatus(int $status): string
    {
        return match (true) {
            $status === 401, $status === 403 => InvalidApiKeyException::CODE,
            $status === 402 => InsufficientBalanceException::CODE,
            $status === 404 => TaskNotFoundException::CODE,
            $status === 429 => RateLimitedException::CODE,
            $status === 503 => CapacityUnavailableException::CODE,
            $status >= 500 => UpstreamErrorException::CODE,
            default => 'http_error',
        };
    }

    /** Whether the same request may succeed if sent again later. */
    public function isRetryable(): bool
    {
        return in_array($this->errorCode, ['rate_limited', 'capacity_unavailable', 'upstream_error', 'unsolvable', 'score_not_met'], true);
    }
}
