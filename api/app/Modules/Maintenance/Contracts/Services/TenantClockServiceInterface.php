<?php

declare(strict_types=1);

namespace Mordomus\Maintenance\Contracts\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

interface TenantClockServiceInterface
{
    /** Fuso da residência ativa (ou o do sistema, como último recurso). */
    public function timezone(Request $request): string;

    /** Converte o campo calendário do payload para o instante UTC do tenant. */
    public function instant(Request $request, string $field, string $timezone): ?Carbon;
}
