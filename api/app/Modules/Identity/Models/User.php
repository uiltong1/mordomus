<?php

namespace Mordomus\Identity\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Mordomus\Common\Eloquent\TenantGlobalScope;
use Mordomus\Identity\Contracts\Services\CapabilityResolverServiceInterface;

#[Fillable(['name', 'email', 'password_hash', 'locale'])]
#[Hidden(['password_hash'])]
class User extends Authenticatable
{
    use HasFactory, HasUlids, Notifiable;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class)
            ->withoutGlobalScope(TenantGlobalScope::class);
    }

    public function activeMembershipIn(string $tenantId): ?Membership
    {
        return $this->memberships()
            ->with('role')
            ->where('tenant_id', $tenantId)
            ->where('status', Membership::STATUS_ACTIVE)
            ->first();
    }

    public function hasCapability(string $capability): bool
    {
        $tenantId = app()->bound('mordomus.tenant_id') ? app('mordomus.tenant_id') : null;
        $membership = $tenantId ? $this->activeMembershipIn($tenantId) : null;

        if (! $membership) {
            return false;
        }

        return app(CapabilityResolverServiceInterface::class)->allows($membership, $capability);
    }
}
