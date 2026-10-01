<?php

declare(strict_types=1);

namespace Mordomus\Financial\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Mordomus\Financial\Contracts\Repositories\SplitResultRepositoryInterface;
use Mordomus\Financial\Models\SplitResult;

final class SplitResultRepository implements SplitResultRepositoryInterface
{
    public function forOccurrence(string $tenantId, string $billOccurrenceId): Collection
    {
        return $this->query()
            ->where('tenant_id', $tenantId)
            ->where('bill_occurrence_id', $billOccurrenceId)
            ->orderBy('id')
            ->get();
    }

    public function find(string $tenantId, string $billOccurrenceId, string $userId): ?SplitResult
    {
        return $this->query()
            ->where('tenant_id', $tenantId)
            ->where('bill_occurrence_id', $billOccurrenceId)
            ->where('user_id', $userId)
            ->first();
    }

    public function sync(string $tenantId, string $billOccurrenceId, array $shares): Collection
    {
        $previous = $this->forOccurrence($tenantId, $billOccurrenceId)->keyBy('user_id');
        $saved = [];

        foreach ($shares as $share) {
            $existing = $previous->get($share['user_id']);
            $changed = $existing === null || $existing->share_amount !== $share['amount'];

            $result = $existing ?? new SplitResult([
                'tenant_id' => $tenantId,
                'bill_occurrence_id' => $billOccurrenceId,
            ]);

            $result->fill([
                'user_id' => $share['user_id'],
                'share_amount' => $share['amount'],
                // A baixa vale para o número com que o morador concordou. Se o
                // valor mudou, aquilo que ele quitou já não é a cota atual, e
                // manter a marca diria que uma cota diferente foi paga.
                'settled' => $changed ? false : $result->settled,
                'settled_at' => $changed ? null : $result->settled_at,
            ]);

            $result->save();

            $saved[$share['user_id']] = $result;
        }

        // Morador que saiu da regra perde a cota: a linha é apagada, e não
        // zerada, porque uma cota zerada continuaria entrando na soma da R5 e
        // a conta apareceria como fechada.
        foreach ($previous as $userId => $result) {
            if (! array_key_exists($userId, $saved)) {
                $result->delete();
            }
        }

        return collect($shares)
            ->map(fn (array $share): SplitResult => $saved[$share['user_id']])
            ->values();
    }

    public function forget(string $tenantId, string $billOccurrenceId): int
    {
        return SplitResult::query()
            ->where('tenant_id', $tenantId)
            ->where('bill_occurrence_id', $billOccurrenceId)
            ->delete();
    }

    public function settle(SplitResult $result, bool $settled, ?string $settledAt): SplitResult
    {
        // Idempotente: repetir a baixa devolve a linha como está, e o instante
        // gravado continua sendo o da primeira, do mesmo jeito que a baixa do
        // vencimento.
        if ($result->isSettled() === $settled) {
            return $result;
        }

        $result->fill([
            'settled' => $settled,
            'settled_at' => $settled ? $settledAt : null,
        ]);

        $result->save();

        return $result;
    }

    /**
     * Base das leituras.
     *
     * O morador não vem junto: o nome vem da entrada da regra, que é quem sabe
     * quem participa, e uma segunda consulta para o mesmo nome seria o mesmo
     * dado lido duas vezes.
     *
     * @return Builder<SplitResult>
     */
    private function query(): Builder
    {
        return SplitResult::query();
    }
}
