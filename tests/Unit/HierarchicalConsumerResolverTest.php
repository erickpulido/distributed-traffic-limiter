<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Traffic\Resolvers\HierarchicalConsumerResolver;
use Illuminate\Http\Request;
use Tests\TestCase;

class HierarchicalConsumerResolverTest extends TestCase
{
    private HierarchicalConsumerResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new HierarchicalConsumerResolver();
    }

    public function test_prioritizes_api_key_over_all_other_identifiers(): void
    {
        $request = Request::create('/api/v1/resource', 'GET');
        $request->headers->set('X-Api-Key', 'secret_key_123');
        $request->headers->set('X-Tenant-ID', 'tenant_xyz');

        $resolved = $this->resolver->resolve($request);

        $expectedHash = 'key:' . hash('xxh128', 'secret_key_123');
        $this->assertSame($expectedHash, $resolved);
    }

    public function test_resolves_tenant_when_no_api_key_is_present(): void
    {
        $request = Request::create('/api/v1/resource', 'GET');
        $request->headers->set('X-Tenant-ID', 'corp_acme');

        $resolved = $this->resolver->resolve($request);

        $this->assertSame('tenant:corp_acme', $resolved);
    }

    public function test_falls_back_to_ip_when_no_credentials_are_present(): void
    {
        $request = Request::create('/api/v1/public', 'GET', [], [], [], [
            'REMOTE_ADDR' => '192.168.1.100',
        ]);

        $resolved = $this->resolver->resolve($request);

        $expectedHash = 'ip:' . hash('xxh128', '192.168.1.100');
        $this->assertSame($expectedHash, $resolved);
    }
}