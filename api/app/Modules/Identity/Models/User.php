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

    /** PK ULID char(26) */
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
     * Resolução de capability no tenant ativo da requisição:
     * membership_grants (override) → role_permissions[role_id].
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
