<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Traffic\FailMode;
use App\Infrastructure\Traffic\TokenBucketLimiter;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class TokenBucketLimiterTest extends TestCase
{
    private TokenBucketLimiter $limiter;
    private string $testKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->limiter = app(TokenBucketLimiter::class);
        $this->testKey = 'test:bucket:' . uniqid();
        Redis::del($this->testKey);
    }

    protected function tearDown(): void
    {
        Redis::del($this->testKey);
        parent::tearDown();
    }

    public function test_allows_initial_request_and_deducts_tokens(): void
    {
        // Capacidad: 5, Recarga: 1 token/s, Costo: 1
        $result = $this->limiter->consume($this->testKey, capacity: 5.0, refillRate: 1.0, cost: 1.0);

        $this->assertTrue($result->isAllowed);
        $this->assertSame(4, $result->remainingTokens);
        $this->assertSame(0.0, $result->retryAfterSeconds);
        $this->assertSame(5, $result->limitCapacity);
    }

    public function test_blocks_request_when_tokens_are_exhausted(): void
    {
        // Agotar los 5 tokens de golpe
        $first = $this->limiter->consume($this->testKey, capacity: 5.0, refillRate: 1.0, cost: 5.0);
        $this->assertTrue($first->isAllowed);
        $this->assertSame(0, $first->remainingTokens);

        // La siguiente petición debe ser rechazada
        $blocked = $this->limiter->consume($this->testKey, capacity: 5.0, refillRate: 1.0, cost: 1.0);
        $this->assertFalse($blocked->isAllowed);
        $this->assertSame(0, $blocked->remainingTokens);
        $this->assertGreaterThan(0.0, $blocked->retryAfterSeconds);
    }

    public function test_refills_tokens_over_time(): void
    {
        // Vaciar el bucket (capacidad 2, recarga 2 tokens/segundo)
        $this->limiter->consume($this->testKey, capacity: 2.0, refillRate: 2.0, cost: 2.0);

        // Esperar 1 segundo para que recargue 2 tokens
        sleep(1);

        $result = $this->limiter->consume($this->testKey, capacity: 2.0, refillRate: 2.0, cost: 1.0);
        $this->assertTrue($result->isAllowed);
        $this->assertGreaterThanOrEqual(0, $result->remainingTokens);
    }

    public function test_circuit_breaker_fails_open_on_redis_outage(): void
    {
        // Instancia apuntando a una llave inválida o simulando fallo con script inexistente
        $brokenLimiter = new TokenBucketLimiter('non_existent_script.lua');

        $result = $brokenLimiter->consume($this->testKey, capacity: 10.0, refillRate: 1.0, cost: 1.0, failMode: FailMode::OPEN);

        $this->assertTrue($result->isAllowed);
        $this->assertTrue($result->fallbackTriggered);
    }
}