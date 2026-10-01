<?php

declare(strict_types=1);

namespace App\Infrastructure\Traffic\Resolvers;

use App\Infrastructure\Traffic\Contracts\ConsumerResolverInterface;
use Illuminate\Http\Request;

class HierarchicalConsumerResolver implements ConsumerResolverInterface
{
    public function resolve(Request $request): string
    {
        // 1. API-Key (Prioridad Máxima: Integradores / Machine-to-Machine)
        if ($apiKey = $request->header('X-Api-Key')) {
            return 'key:' . hash('xxh128', (string) $apiKey);
        }

        // 2. Tenant ID (SaaS Multi-Tenant / Empresa cliente)
        if ($tenantId = $request->header('X-Tenant-ID')) {
            return 'tenant:' . preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $tenantId);
        }

        // 3. Usuario autenticado (Sesión o Token de usuario)
        if ($user = $request->user()) {
            return 'user:' . $user->getAuthIdentifier();
        }

        // 4. Fallback: Dirección IP segura (resuelve cabeceras de proxy/load balancer)
        $ip = $request->ip() ?? '127.0.0.1';
        return 'ip:' . hash('xxh128', $ip);
    }
}