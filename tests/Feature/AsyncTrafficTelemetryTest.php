<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RecordTrafficAnomalyJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AsyncTrafficTelemetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/api/telemetry-test', fn () => response()->json(['status' => 'ok']))
            ->middleware('traffic.limit:1,1,1,open');
    }

    public function test_dispatches_anomaly_job_when_rate_limit_is_exceeded(): void
    {
        Queue::fake();

        $headers = ['X-Tenant-ID' => 'tenant_telemetry'];

        // Consumir el único token disponible
        $this->getJson('/api/telemetry-test', $headers)->assertStatus(200);

        // Provocar el bloqueo (429)
        $this->getJson('/api/telemetry-test', $headers)->assertStatus(429);

        // Validar que el Job fue encolado con los parámetros correctos
        Queue::assertPushed(RecordTrafficAnomalyJob::class, function (RecordTrafficAnomalyJob $job) {
            return $job->eventType === 'rate_limit_exceeded'
                && $job->consumerId === 'tenant:tenant_telemetry';
        });
    }
}