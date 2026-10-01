<?php

declare(strict_types=1);

namespace App\Infrastructure\Traffic;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class TokenBucketLimiter
{
    private ?string $scriptSha = null;

    public function __construct(
        private readonly string $scriptPath = 'app/Infrastructure/Traffic/token_bucket.lua'
    ) {}

    /**
     * Evalúa y consume tokens de forma atómica y resiliente.
     */
    public function consume(
        string $key,
        float $capacity,
        float $refillRate,
        float $cost = 1.0,
        FailMode $failMode = FailMode::OPEN
    ): RateLimitResult {
        try {
            $response = $this->executeLuaScript($key, $capacity, $refillRate, $cost);

            return new RateLimitResult(
                isAllowed: (bool) $response[0],
                remainingTokens: (int) $response[1],
                retryAfterSeconds: (float) $response[2],
                limitCapacity: (int) $response[3]
            );
        } catch (Throwable $e) {
            return $this->handleFailure($e, $failMode, (int) $capacity);
        }
    }

    /**
     * Ejecuta el script usando EVALSHA, con fallback ante reinicios de Redis.
     */
    private function executeLuaScript(string $key, float $capacity, float $refillRate, float $cost): array
    {
        $redis = Redis::connection();
        $now = microtime(true);

        if ($this->scriptSha === null) {
            $this->scriptSha = $redis->script('load', file_get_contents(base_path($this->scriptPath)));
        }

        try {
            return (array) $redis->evalsha($this->scriptSha, 1, $key, $capacity, $refillRate, $cost, $now);
        } catch (Throwable $e) {
            // Si Redis se reinició, el script se borró de su RAM (error NOSCRIPT)
            if (str_contains($e->getMessage(), 'NOSCRIPT')) {
                $this->scriptSha = $redis->script('load', file_get_contents(base_path($this->scriptPath)));
                return (array) $redis->evalsha($this->scriptSha, 1, $key, $capacity, $refillRate, $cost, $now);
            }
            throw $e;
        }
    }

    /**
     * Circuit Breaker: Aplica estrategia ante caídas de infraestructura.
     */
    private function handleFailure(Throwable $e, FailMode $failMode, int $capacity): RateLimitResult
    {
        Log::critical('Rate Limiter Engine Failure. Circuit Breaker activated.', [
            'exception' => $e->getMessage(),
            'fail_mode' => $failMode->value,
        ]);

        if ($failMode === FailMode::CLOSED) {
            throw new HttpException(503, 'Traffic control service temporarily unavailable.');
        }

        // Fail-Open: Se permite el paso con degradación agraciada
        return new RateLimitResult(
            isAllowed: true,
            remainingTokens: $capacity,
            retryAfterSeconds: 0.0,
            limitCapacity: $capacity,
            fallbackTriggered: true
        );
    }
}