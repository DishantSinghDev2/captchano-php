<?php

declare(strict_types=1);

namespace CaptchaNo\Models;

/** What a solved challenge produced. */
final class Solution
{
    public function __construct(
        /** Token to submit, for token-based challenges. */
        public readonly ?string $token = null,
        /** Cookie to set, for session-graded systems such as DataDome. */
        public readonly ?string $cookie = null,
        /** Plain text, for image captchas. */
        public readonly ?string $text = null,
        /** User agent the solution is bound to. Replay it exactly. */
        public readonly ?string $userAgent = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $s = static fn (string $k): ?string => isset($data[$k]) && is_scalar($data[$k]) ? (string) $data[$k] : null;

        return new self($s('token'), $s('cookie'), $s('text'), $s('userAgent'));
    }
}
