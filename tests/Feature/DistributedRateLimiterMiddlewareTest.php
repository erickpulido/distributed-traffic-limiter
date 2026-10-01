<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DistributedRateLimiterMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ruta efímera de prueba: Capacidad 2, Recarga 1 token/s, Costo 1, Fail-Open
        Route::get('/api/test-traffic', fn () => response()->json(['status' => 'ok']))
            ->middleware('traffic.limit:2,1,1,open');
    }

    public function test_injects_ratelimit_headers_on_successful_requests(): void
    {
        $response = $this->getJson('/api/test-traffic', [
            'X-Tenant-ID' => 'tenant_alpha',
        ]);

        $response->assertStatus(200);
        $response->assertHeader('RateLimit-Limit', '2');
        $response->assertHeader('RateLimit-Remaining', '1');
    }

    public function test_returns_429_with_retry_after_when_burst_exhausted(): void
    {
        $headers = ['X-Tenant-ID' => 'tenant_burst'];

        // Consumir token 1
        $this->getJson('/api/test-traffic', $headers)->assertStatus(200);

        // Consumir token 2 (bucket agotado)
        $this->getJson('/api/test-traffic', $headers)->assertStatus(200);

        // Petición 3: Debe disparar HTTP 429
        $blockedResponse = $this->getJson('/api/test-traffic', $headers);

        $blockedResponse->assertStatus(429);
        $blockedResponse->assertHeader('Retry-After');
        $blockedResponse->assertHeader('RateLimit-Remaining', '0');
        $blockedResponse->assertJsonStructure([
            'error',
            'message',
            'retry_after',
        ]);
    }
}