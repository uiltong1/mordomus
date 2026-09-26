<?php

namespace Tests\Unit;

use Mordomus\Identity\Services\JwtIssuer;
use Tests\TestCase;

/**
 * T1.2.3 — contrato das claims do JWT de usuário (TECHSPEC §4.1).
 */
class JwtClaimsTest extends TestCase
{
    public function test_claims_follow_techspec_contract(): void
    {
        $issuer = new JwtIssuer();
        $timestamp = 1767225600;

        $claims = $issuer->claims(
            '01K3Z8Q9M7X4B2V6T0C5N8D9F1',
            '01K3Z8Q9M7X4B2V6T0C5N8D9F2',
            ['01K3Z8Q9M7X4B2V6T0C5N8D9F2', '01K3Z8Q9M7X4B2V6T0C5N8D9F3'],
            $timestamp,
        );

        $this->assertSame('mordomus', $claims['iss']);
        $this->assertSame('01K3Z8Q9M7X4B2V6T0C5N8D9F1', $claims['sub']);
        $this->assertSame('01K3Z8Q9M7X4B2V6T0C5N8D9F2', $claims['tid']);
        $this->assertSame(
            ['01K3Z8Q9M7X4B2V6T0C5N8D9F2', '01K3Z8Q9M7X4B2V6T0C5N8D9F3'],
            $claims['tenants'],
        );
        $this->assertSame($timestamp, $claims['iat']);
        $this->assertSame($timestamp + 3600, $claims['exp']); // JWT_TTL padrão = 60 min
        $this->assertNotEmpty($claims['jti']);
        $this->assertSame(26, strlen($claims['jti']));
    }

    public function test_active_tenant_may_be_null(): void
    {
        $claims = (new JwtIssuer())->claims('user-1', null, [], 1000);

        $this->assertNull($claims['tid']);
        $this->assertSame([], $claims['tenants']);
    }

    public function test_tenants_are_reindexed(): void
    {
        $claims = (new JwtIssuer())->claims('user-1', 't-2', [5 => 't-2', 9 => 't-3'], 1000);

        $this->assertSame(['t-2', 't-3'], $claims['tenants']);
        $this->assertSame([0, 1], array_keys($claims['tenants']));
    }
}
