<?php

namespace Mordomus\Identity\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Mordomus\Common\Eloquent\TenantGlobalScope;
use Mordomus\Identity\Services\CapabilityResolver;

#[Fillable(['name', 'email', 'password_hash', 'locale'])]
#[Hidden(['password_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUlids, Notifiable;

    /** PK ULID char(26) (ADR-006) */
    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed', // argon2id via HASH_DRIVER=argon2id
        ];
    }

    /**
     * Todas as residências do usuário — relação semanticamente cross-tenant.
     *
     * Os escopes globais saem daqui de propósito: a leitura por residência
     * passa por `Membership::query()` (com `tenant_id`), nunca por esta relação.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class)
            ->withoutGlobalScope(TenantGlobalScope::class);
    }

    /**
     * Tenants com membership ativo, com a role de cada um.
     *
     * @return list<array<string, mixed>>
     */
    public function tenantSummaries(): array
    {
        $resolver = app(CapabilityResolver::class);

        return $this->memberships()
            ->with('tenant', 'role')
            ->where('status', Membership::STATUS_ACTIVE)
            ->orderBy('created_at')
            ->get()
            ->map(fn (Membership $membership) => [
                'id' => $membership->tenant_id,
                'name' => $membership->tenant->name,
                'slug' => $membership->tenant->slug,
                'role' => $membership->role->name,
                'role_key' => $membership->role->key,
                'capabilities' => $resolver->keys($membership),
            ])
            ->all();
    }

    /**
     * Membership ativo em um tenant específico (fonte de verdade do Can()).
     */
    public function activeMembershipIn(string $tenantId): ?Membership
    {
        return $this->memberships()
            ->with('role')
            ->where('tenant_id', $tenantId)
            ->where('status', Membership::STATUS_ACTIVE)
            ->first();
    }

    /**
     * Resolução de capability (ADR-007) no tenant ativo da requisição.
     * ADR-007: membership_grants (override) → role_permissions[role_id].
     */
    public function hasCapability(string $capability): bool
    {
        $tenantId = app()->bound('mordomus.tenant_id') ? app('mordomus.tenant_id') : null;
        $membership = $tenantId ? $this->activeMembershipIn($tenantId) : null;

        if (! $membership) {
            return false;
        }

        return app(CapabilityResolver::class)->allows($membership, $capability);
    }
}
