<?php

declare(strict_types=1);

namespace Mordomus\Financial\Contracts\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Mordomus\Financial\Models\BillOccurrence;
use Mordomus\Financial\Models\SplitResult;
use Mordomus\Financial\Models\SplitRule;

/**
 * Cota-parte dos vencimentos: materializa, consulta, dá baixa e recalcula.
 *
 * A materialização é a única escrita do módulo aqui: ela grava as cotas de um
 * vencimento e publica o aviso de que elas existem. Tudo o mais é leitura, e
 * é por isso que `show` não grava nada — a tela precisa responder mesmo para
 * um vencimento anterior a este recurso, e leitura que escreve esconde o
 * efeito de um segundo acesso.
 */
interface SplitResultServiceInterface
{
    /** @return array<string, mixed> */
    public function show(Request $request, string $billOccurrenceId): array;

    /** @return array<string, mixed> */
    public function settle(Request $request, string $billOccurrenceId): array;

    /**
     * Calcula e grava as cotas de um vencimento, publicando o aviso quando o
     * número muda.
     *
     * @return Collection<int, SplitResult> as cotas gravadas
     */
    public function compute(BillOccurrence $occurrence): Collection;

    /**
     * Refaz as cotas dos vencimentos em aberto que a regra governa.
     *
     * @return int vencimentos recalculados
     */
    public function recalculateForRule(string $tenantId, SplitRule $rule): int;
}
