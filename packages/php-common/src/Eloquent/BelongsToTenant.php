<?php

namespace Mordomus\Common\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mordomus\Common\Support\TenantContext;

/**
 * Adiciona o escopo global de tenant e o preenchimento automático de
 * `tenant_id`.
 *
 * Uso: class Room extends Model { use BelongsToTenant; }
 *
 * Garantias:
 *  - toda leitura é filtrada pelo tenant do contexto (TenantGlobalScope);
 *  - no `creating`, `tenant_id` é SEMPRE sobrescrevido pelo contexto — um
 *    `tenant_id` vindo do corpo da requisição é ignorado, nunca aceito;
 *  - fora de contexto (CLI/seed), o valor explícito é aceito; sem valor, erro.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantGlobalScope);

        static::creating(static function (Model $model): void {
            $tenantId = TenantContext::tenantId();

            if ($tenantId !== null) {
                $model->setAttribute('tenant_id', $tenantId);

                return;
            }

            if (! $model->getAttribute('tenant_id')) {
                throw new \RuntimeException(sprintf(
                    '%s: tenant_id ausente e não há tenant no contexto — use TenantContext::set() ou informe explicitamente em CLI.',
                    $model::class,
                ));
            }
        });
    }

    /** Relação `tenant_id` → Tenant do serviço de identidade (sem FK entre bancos). */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where($query->getModel()->qualifyColumn('tenant_id'), $tenantId);
    }

    public function tenantId(): ?string
    {
        $value = $this->getAttribute('tenant_id');

        return $value === null ? null : (string) $value;
    }

    /** @return BelongsTo<Model, Model> */
    public function scopeWithoutTenantScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantGlobalScope::class);
    }
}
