<?php

namespace Mordomus\Identity\Services;

use Illuminate\Support\Facades\DB;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Role;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Identity\Models\TenantPreference;
use Mordomus\Identity\Models\User;

/**
 * Criação de residência: preferências padrão e a membership `owner` nascem
 * juntas — residência sem dono não existiria nem por um instante.
 */
final class TenantProvisioner
{
    public const DEFAULT_TIMEZONE = 'America/Sao_Paulo';

    public const DEFAULT_NAME = 'Casa Principal';

    public const DEFAULT_PREFERRED_HOUR = '09:00';

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{tenant: Tenant, membership: Membership}
     */
    public function create(User $user, array $attributes): array
    {
        return DB::transaction(function () use ($user, $attributes): array {
            $tenant = Tenant::create([
                'name' => ($attributes['name'] ?? null) ?: self::DEFAULT_NAME,
                'timezone' => ($attributes['timezone'] ?? null) ?: self::DEFAULT_TIMEZONE,
                'preferred_hour' => ($attributes['preferred_hour'] ?? null) ?: self::DEFAULT_PREFERRED_HOUR,
            ]);

            $tenant->preferences()->create([
                'quiet_hours' => TenantPreference::DEFAULT_QUIET_HOURS,
                'channels' => TenantPreference::DEFAULT_CHANNELS,
            ]);

            $membership = Membership::create([
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'role_id' => Role::systemByKey(Role::OWNER)->id,
                'status' => Membership::STATUS_ACTIVE,
            ]);

            return ['tenant' => $tenant, 'membership' => $membership];
        });
    }
}
