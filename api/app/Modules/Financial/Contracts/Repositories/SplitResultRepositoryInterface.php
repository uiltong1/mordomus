<?php

declare(strict_types=1);

namespace Mordomus\Financial\Contracts\Repositories;

use Illuminate\Support\Collection;
use Mordomus\Financial\Models\SplitResult;

interface SplitResultRepositoryInterface
{
    /** @return Collection<int, SplitResult> */
    public function forOccurrence(string $tenantId, string $billOccurrenceId): Collection;

    public function find(string $tenantId, string $billOccurrenceId, string $userId): ?SplitResult;

    /**
     * Grava as cotas de um vencimento, atualizando as que já existem.
     *
     * @param  list<array{user_id: string, amount: string}>  $shares
     * @return Collection<int, SplitResult> as cotas gravadas, na ordem do cálculo
     */
    public function sync(string $tenantId, string $billOccurrenceId, array $shares): Collection;

    /**
     * Apaga todas as cotas de um vencimento.
     *
     * Usado quando a regra some: uma cota sem regra não é uma cota zerada, é
     * uma conta que a casa parou de dividir, e a linha zerada continuaria
     * entrando na soma da R5.
     *
     * @return int cotas removidas
     */
    public function forget(string $tenantId, string $billOccurrenceId): int;

    /**
     * Marca (ou desmarca) a cota como quitada.
     *
     * Quitar de novo não muda nada: o instante gravado é o da primeira baixa,
     * do mesmo jeito que a baixa de pagamento do vencimento.
     */
    public function settle(SplitResult $result, bool $settled, ?string $settledAt): SplitResult;
}
