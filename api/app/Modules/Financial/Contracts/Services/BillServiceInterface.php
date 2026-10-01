<?php

declare(strict_types=1);

namespace Mordomus\Financial\Contracts\Services;

use Illuminate\Http\Request;

/**
 * Cadastro das contas e a cadência que cada uma pede ao Scheduling.
 *
 * A cadência não é gravada aqui: `POST /bills` delega ao módulo Scheduling a
 * regra `CALENDAR_MONTHLY` do dia de vencimento, porque o cálculo da primeira
 * data é do dono do motor de tempo (regra R7).
 */
interface BillServiceInterface
{
    /** @return array<string, mixed> */
    public function index(Request $request): array;

    /** @return array<string, mixed> */
    public function show(Request $request, string $billId): array;

    /** @return array<string, mixed> */
    public function store(Request $request): array;

    /** @return array<string, mixed> */
    public function update(Request $request, string $billId): array;
}
