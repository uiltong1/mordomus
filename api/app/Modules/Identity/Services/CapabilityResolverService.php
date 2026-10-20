<?php

namespace Mordomus\Identity\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mordomus\Identity\Contracts\Services\CapabilityResolverServiceInterface;
use Mordomus\Identity\Models\Membership;

class CapabilityResolverService implements CapabilityResolverServiceInterface
{
    private const TTL_SECONDS = 60;

    public function keys(Membership $membership): array
    {
        return Cache::remember(
            $this->cacheKey($membership),
            self::TTL_SECONDS,
            fn (): array => $this->resolve($membership)
        );
    }

    public function allows(Membership $membership, string $capability): bool
    {
        return in_array($capability, $this->keys($membership), true);
    }

    public function forget(Membership $membership): void
    {
        Cache::forget($this->cacheKey($membership));
    }

    private function resolve(Membership $membership): array
    {
        $capabilities = [];

        $roleKeys = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $membership->role_id)
            ->where('role_permissions.granted', true)
            ->pluck('permissions.key')
            ->all();

        foreach ($roleKeys as $key) {
            $capabilities[$key] = true;
        }

        $grants = DB::table('membership_grants')
            ->join('permissions', 'permissions.id', '=', 'membership_grants.permission_id')
            ->where('membership_grants.membership_id', $membership->id)
            ->select('permissions.key', 'membership_grants.granted')
            ->get();

        foreach ($grants as $grant) {
            if ($grant->granted) {
                $capabilities[$grant->key] = true;
            } else {
                unset($capabilities[$grant->key]);
            }
        }

        ksort($capabilities);

        return array_keys($capabilities);
    }

    private function cacheKey(Membership $membership): string
    {
        return sprintf('mordomus:rbac:membership:%s', $membership->id);
    }
}
