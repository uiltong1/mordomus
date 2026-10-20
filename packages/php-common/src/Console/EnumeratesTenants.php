<?php

namespace Mordomus\Common\Console;

use Illuminate\Support\Facades\DB;

trait EnumeratesTenants
{

    private function tenantIds(): array
    {
        return array_map(
            strval(...),
            DB::table('tenants')
                ->whereNull('archived_at')
                ->orderBy('id')
                ->pluck('id')
                ->all(),
        );
    }
}
