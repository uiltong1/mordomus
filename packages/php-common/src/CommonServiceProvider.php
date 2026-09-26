<?php

namespace Mordomus\Common;

use Illuminate\Support\ServiceProvider;

/**
 * Ponto de integração Laravel do pacote.
 *
 * Depois da ADR-011 (monólito modular) não há mais JWT de serviço nem cache
 * de membership: o middleware `TenantScope` e a checagem de capability vivem em
 * `api/app/Http/Middleware/` e o membership é lido do banco.
 *
 * O pacote fica responsável apenas pelas primitivas de escopo de tenant
 * (TenantContext, BelongsToTenant, TenantGlobalScope) e pelo formato de erro.
 */
class CommonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
