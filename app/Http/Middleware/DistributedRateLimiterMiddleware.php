<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Infrastructure\Traffic\Contracts\ConsumerResolverInterface;
use App\Infrastructure\Traffic\FailMode;
use App\Infrastructure\Traffic\TokenBucketLimiter;
use App\Jobs\RecordTrafficAnomalyJob;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DistributedRateLimiterMiddleware
{
    public function __construct(
        private readonly TokenBucketLimiter $limiter,
        private readonly ConsumerResolverInterface $resolver
    ) {}

    /**
     * Aplica control de tráfico atómico basado en Token Bucket.
     *
     * Uso en rutas: ->middleware('traffic.limit:capacity,refill_rate,cost,fail_mode')
     * Ejemplo:      ->middleware('traffic.limit:10,2,1,open')
     */
    public function handle(
        Request $request,
        Closure $next,
        float|int $capacity = 60,
        float|int $refillRate = 1,
        float|int $cost = 1,
        string $failMode = 'open'
    ): Response {
        $consumerId = $this->resolver->resolve($request);
        $routeSignature = $request->route()?->getName() ?? $request->path();
        $bucketKey = "ratelimit:{$consumerId}:" . hash('xxh128', $routeSignature);

        $mode = FailMode::tryFrom(strtolower($failMode)) ?? FailMode::OPEN;

        $result = $this->limiter->consume(
            key: $bucketKey,
            capacity: (float) $capacity,
            refillRate: (float) $refillRate,
            cost: (float) $cost,
            failMode: $mode
        );

        // 1. Detección de Bloqueo por Exceso de Tráfico
        if (! $result->isAllowed) {
            RecordTrafficAnomalyJob::dispatch(
                eventType: 'rate_limit_exceeded',
                consumerId: $consumerId,
                route: $routeSignature,
                metadata: [
                    'ip' => $request->ip(),
                    'cost' => $cost,
                    'retry_after' => (int) $result->retryAfterSeconds,
                ]
            );

            return new JsonResponse(
                data: [
                    'error' => 'Too Many Requests',
                    'message' => 'Rate limit exceeded. Please back off before retrying.',
                    'retry_after' => (int) $result->retryAfterSeconds,
                ],
                status: Response::HTTP_TOO_MANY_REQUESTS,
                headers: [
                    'Content-Type' => 'application/json',
                    'Retry-After' => (string) (int) $result->retryAfterSeconds,
                    'RateLimit-Limit' => (string) $result->limitCapacity,
                    'RateLimit-Remaining' => '0',
                    'RateLimit-Reset' => (string) (int) $result->retryAfterSeconds,
                ]
            );
        }

        // 2. Detección de Activación de Fallback (Circuit Breaker)
        if ($result->fallbackTriggered) {
            RecordTrafficAnomalyJob::dispatch(
                eventType: 'circuit_breaker_activated',
                consumerId: $consumerId,
                route: $routeSignature,
                metadata: ['ip' => $request->ip()]
            );
        }

        /** @var Response $response */
        $response = $next($request);

        // Inyección de cabeceras estándar IETF en respuestas exitosas
        $response->headers->set('RateLimit-Limit', (string) $result->limitCapacity);
        $response->headers->set('RateLimit-Remaining', (string) $result->remainingTokens);
        $response->headers->set('RateLimit-Reset', (string) (int) ceil(($result->limitCapacity - $result->remainingTokens) / (float) $refillRate));

        if ($result->fallbackTriggered) {
            $response->headers->set('X-Traffic-Fallback', 'true');
        }

        return $response;
    }
}