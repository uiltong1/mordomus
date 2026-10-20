<?php

namespace Mordomus\Maintenance\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Mordomus\Http\Tenancy\ActiveTenant;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Maintenance\Contracts\Services\TenantClockServiceInterface;

final class TenantClockService implements TenantClockServiceInterface
{
    public function timezone(Request $request): string
    {
        $tenantId = ActiveTenant::id($request);
        $tenant = $tenantId ? Tenant::query()->find($tenantId) : null;

        return $tenant?->timezone ?: 'America/Sao_Paulo';
    }

    public function instant(Request $request, string $field, string $timezone): ?Carbon
    {
        $value = $request->input($field);

        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value, $timezone)->utc();
    }
}
