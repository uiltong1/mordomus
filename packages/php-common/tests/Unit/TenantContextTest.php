<?php

namespace Mordomus\Common\Tests\Unit;

use Mordomus\Common\Support\TenantContext;
use Mordomus\Common\Tests\TestCase;

class TenantContextTest extends TestCase
{
    public function test_set_has_e_clear(): void
    {
        $this->assertFalse(TenantContext::has());
        $this->assertNull(TenantContext::tenantId());

        TenantContext::set('01TENANT', '01USER', ['tid' => '01TENANT']);

        $this->assertTrue(TenantContext::has());
        $this->assertSame('01TENANT', TenantContext::tenantId());
        $this->assertSame('01USER', TenantContext::userId());
        $this->assertSame(['tid' => '01TENANT'], TenantContext::claims());

        TenantContext::clear();

        $this->assertFalse(TenantContext::has());
        $this->assertNull(TenantContext::userId());
        $this->assertNull(TenantContext::claims());
    }

    public function test_run_with_restaura_o_contexto_anterior_mesmo_com_excecao(): void
    {
        TenantContext::set('01OUTRO', '01USER');

        try {
            TenantContext::runWith('01NOVO', function (): void {
                $this->assertSame('01NOVO', TenantContext::tenantId());
                throw new \RuntimeException('boom');
            });
            $this->fail('A exceção do callback deveria propagar.');
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertSame('01OUTRO', TenantContext::tenantId());
    }

    public function test_run_with_devolve_o_resultado_do_callback(): void
    {
        $resultado = TenantContext::runWith('01NOVO', fn (): string => TenantContext::tenantId());

        $this->assertSame('01NOVO', $resultado);
        $this->assertNull(TenantContext::tenantId());
    }
}
