<?php

declare(strict_types=1);

namespace CaptchaNo\Models;

/** A solve job, as returned by POST /v1/solve and GET /v1/task/{id}. */
final class Task
{
    public const QUEUED = 'queued';
    public const SOLVING = 'solving';
    public const READY = 'ready';
    public const FAILED = 'failed';

    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $id,
        /** queued | solving | ready | failed */
        public readonly string $status,
        public readonly ?string $type = null,
        public readonly ?Solution $solution = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        /** USD charged, as a decimal string. Present once ready. */
        public readonly ?string $cost = null,
        /** "plan" or "balance". Present once ready. */
        public readonly ?string $billedFrom = null,
        /** Seconds from submission to solution. */
        public readonly ?float $solveTime = null,
        /** Unix epoch milliseconds. */
        public readonly ?int $createdAt = null,
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $error = is_array($data['error'] ?? null) ? $data['error'] : [];

        return new self(
            id: (string) $data['id'],
            status: (string) $data['status'],
            type: isset($data['type']) ? (string) $data['type'] : null,
            solution: is_array($data['solution'] ?? null) ? Solution::fromArray($data['solution']) : null,
            errorCode: isset($error['code']) && is_string($error['code']) ? $error['code'] : null,
            errorMessage: isset($error['message']) && is_string($error['message']) ? $error['message'] : null,
            cost: isset($data['cost']) && is_scalar($data['cost']) ? (string) $data['cost'] : null,
            billedFrom: isset($data['billedFrom']) && is_string($data['billedFrom']) ? $data['billedFrom'] : null,
            solveTime: isset($data['solveTime']) && is_numeric($data['solveTime']) ? (float) $data['solveTime'] : null,
            createdAt: isset($data['createdAt']) && is_numeric($data['createdAt']) ? (int) $data['createdAt'] : null,
            raw: $data,
        );
    }

    /** @param mixed $data */
    public static function looksLikeTask(mixed $data): bool
    {
        return is_array($data)
            && isset($data['id'], $data['status'])
            && is_string($data['id']) && $data['id'] !== ''
            && in_array($data['status'], [self::QUEUED, self::SOLVING, self::READY, self::FAILED], true);
    }

    /** Ready or failed. */
    public function isDone(): bool
    {
        return $this->status === self::READY || $this->status === self::FAILED;
    }

    public function isReady(): bool
    {
        return $this->status === self::READY;
    }

    /** Shortcut for $task->solution->token. */
    public function token(): ?string
    {
        return $this->solution?->token;
    }
}
