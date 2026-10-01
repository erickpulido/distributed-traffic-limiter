<?php

declare(strict_types=1);

namespace App\Infrastructure\Traffic;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
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
                limitCapacity: (int) $response[3],
                fallbackTriggered: false
            );
        } catch (Throwable $e) {
            return $this->handleFailure($e, $failMode, (int) $capacity);
        }
    }

    /**
     * Ejecuta el script usando el cliente de PhpRedis con firma estricta.
     */
    private function executeLuaScript(string $key, float $capacity, float $refillRate, float $cost): array
    {
        /** @var \Redis $client */
        $client = Redis::connection()->client();
        $now = microtime(true);

        $fullPath = base_path($this->scriptPath);
        if (! file_exists($fullPath)) {
            throw new RuntimeException("Lua script not found at path: {$fullPath}");
        }

        // Carga inicial del script en la RAM de Redis
        if ($this->scriptSha === null) {
            $this->scriptSha = (string) $client->script('load', file_get_contents($fullPath));
        }

        // PhpRedis: Todos los parámetros (KEYS + ARGV) en un solo array plano
        $arguments = [$key, $capacity, $refillRate, $cost, $now];
        $numKeys = 1;

        // Ejecución con EVALSHA
        $rawResponse = $client->evalSha($this->scriptSha, $arguments, $numKeys);

        // Si Redis se reinició (error NOSCRIPT), recargamos y reintentamos
        if ($rawResponse === false && str_contains((string) $client->getLastError(), 'NOSCRIPT')) {
            $client->clearLastError();
            $this->scriptSha = (string) $client->script('load', file_get_contents($fullPath));
            $rawResponse = $client->evalSha($this->scriptSha, $arguments, $numKeys);
        }

        // Si devuelve false por cualquier otro motivo, capturamos el mensaje exacto de Redis
        if ($rawResponse === false) {
            $error = $client->getLastError() ?: 'Unknown Redis EVALSHA error';
            $client->clearLastError();
            throw new RuntimeException("Redis Lua Error: {$error}");
        }

        return (array) $rawResponse;
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