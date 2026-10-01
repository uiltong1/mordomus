<?php

declare(strict_types=1);

namespace Mordomus\Financial\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Mordomus\Financial\Contracts\Repositories\SplitRuleRepositoryInterface;
use Mordomus\Financial\Models\SplitRule;

final class SplitRuleRepository implements SplitRuleRepositoryInterface
{
    public function list(string $tenantId): Collection
    {
        return $this->query()
            ->where('tenant_id', $tenantId)
            // O padrão da casa abre a lista: é a regra que vale para tudo que
            // não tem regra própria, e escondê-la no fim da lista deixaria a
            // casa sem enxergar o que está valendo.
            ->orderByRaw('(bill_id is null) desc')
            ->orderBy('bill_id')
            ->get();
    }

    public function find(string $splitRuleId, ?string $tenantId): ?SplitRule
    {
        return $this->query()
            ->where('id', $splitRuleId)
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->first();
    }

    public function findForBill(string $tenantId, ?string $billId): ?SplitRule
    {
        return $this->query()
            ->where('tenant_id', $tenantId)
            ->when($billId === null, fn (Builder $query) => $query->whereNull('bill_id'))
            ->when($billId !== null, fn (Builder $query) => $query->where('bill_id', $billId))
            ->first();
    }

    public function findEffective(string $tenantId, ?string $billId): ?SplitRule
    {
        if ($billId === null) {
            return $this->houseDefault($tenantId);
        }

        // A regra da conta só vence se estiver ativa: pausada, a casa volta ao
        // padrão. Do contrário, desligar a regra de uma conta tiraria a divisão
        // dela em vez de devolver a regra geral.
        return $this->active($tenantId)->where('bill_id', $billId)->first()
            ?? $this->houseDefault($tenantId);
    }

    public function billIdsWithOwnRule(string $tenantId): array
    {
        return SplitRule::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('bill_id')
            ->distinct()
            ->pluck('bill_id')
            ->map(fn (mixed $billId): string => (string) $billId)
            ->all();
    }

    public function create(array $attributes): SplitRule
    {
        return SplitRule::create($attributes)->refresh();
    }

    public function change(SplitRule $rule, array $attributes): SplitRule
    {
        $rule->fill($attributes);
        $rule->save();

        return $rule;
    }

    public function replaceEntries(SplitRule $rule, array $entries): SplitRule
    {
        $rule->entries()->delete();

        foreach ($entries as $entry) {
            $rule->entries()->create($entry);
        }

        return $rule->refresh();
    }

    private function houseDefault(string $tenantId): ?SplitRule
    {
        return $this->active($tenantId)->whereNull('bill_id')->first();
    }

    /** @return Builder<SplitRule> */
    private function active(string $tenantId): Builder
    {
        return $this->query()->where('tenant_id', $tenantId)->where('is_active', true);
    }

    /**
     * Base das leituras, com os participantes e seus moradores já anexados.
     *
     * @return Builder<SplitRule>
     */
    private function query(): Builder
    {
        return SplitRule::query()->with(['entries.user', 'bill']);
    }
}
