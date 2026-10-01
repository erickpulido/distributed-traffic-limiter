<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RecordTrafficAnomalyJob implements ShouldQueue
{
    use Queueable;

    /**
     * Reintentos mínimos y timeout estricto para no saturar workers.
     */
    public int $tries = 2;
    public int $timeout = 5;

    public function __construct(
        public readonly string $eventType,     // 'rate_limit_exceeded' | 'circuit_breaker_activated'
        public readonly string $consumerId,
        public readonly string $route,
        public readonly array $metadata = []
    ) {}

    public function handle(): void
    {
        // Log estructurado en JSON (stdout) listo para ingesta en ClickHouse / Vector
        Log::warning('traffic_anomaly_detected', [
            'event'       => $this->eventType,
            'consumer_id' => $this->consumerId,
            'route'       => $this->route,
            'metadata'    => $this->metadata,
            'recorded_at' => microtime(true),
        ]);
    }
}