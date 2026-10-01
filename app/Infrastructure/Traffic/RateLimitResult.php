<?php

declare(strict_types=1);

namespace App\Infrastructure\Traffic;

readonly class RateLimitResult
{
    public function __construct(
        public bool $isAllowed,
        public int $remainingTokens,
        public float $retryAfterSeconds,
        public int $limitCapacity,
        public bool $fallbackTriggered = false
    ) {}
}