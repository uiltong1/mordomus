<?php

namespace Mordomus\Common\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Mordomus\Common\Support\TenantContext;

/**
 * Escopo global de tenant (regra R1 / ADR-004).
 *
 * Toda leitura é restringida ao tenant do contexto. Sem contexto — CLI,
 * seeders, jobs — o escopo não deixa passar NENHUMA linha (fail closed);
 * quem precisa varrer tudo chama withoutGlobalScope(TenantGlobalScope::class)
 * de forma explícita e consciente.
 */
class TenantGlobalScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = TenantContext::tenantId();

        if ($tenantId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
