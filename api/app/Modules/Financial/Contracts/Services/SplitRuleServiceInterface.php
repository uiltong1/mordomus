<?php

declare(strict_types=1);

namespace Mordomus\Financial\Contracts\Services;

use Illuminate\Http\Request;

interface SplitRuleServiceInterface
{
    /** @return array<string, mixed> */
    public function index(Request $request): array;

    /**
     * Grava a regra de divisão de uma conta (ou a padrão da casa) e refaz as
     * cotas dos vencimentos em aberto que ela governa.
     *
     * @return array<string, mixed>
     */
    public function update(Request $request): array;
}
