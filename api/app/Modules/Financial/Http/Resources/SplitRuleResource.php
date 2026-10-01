<?php

namespace Mordomus\Financial\Http\Resources;

use Mordomus\Financial\Models\SplitEntry;
use Mordomus\Financial\Models\SplitRule;

/**
 * Shape da regra de divisão, com quem participa e em que proporção.
 *
 * O morador vem dentro de cada entrada, e não numa lista separada: a regra é
 * lida para montar a tela de divisão, e um id sem nome ali obriga a uma
 * segunda chamada para saber a quem o peso pertence.
 *
 * `is_house_default` separa a regra padrão das regras por conta. Sem isso a
 * tela não distinguiria "esta conta é dividida assim" de "a casa inteira é
 * dividida assim", que são decisões diferentes do dono da casa.
 */
final class SplitRuleResource
{
    /**
     * @return array<string, mixed>
     */
    public function make(SplitRule $rule): array
    {
        return [
            'id' => $rule->id,
            'tenant_id' => $rule->tenant_id,
            'bill_id' => $rule->bill_id,
            'bill_name' => $rule->bill?->name,
            'mode' => $rule->mode,
            'is_active' => $rule->isActive(),
            'is_house_default' => $rule->isHouseDefault(),
            'entries' => $this->entries($rule),
            'created_at' => $rule->created_at?->toIso8601String(),
            'updated_at' => $rule->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  iterable<SplitRule>  $rules
     * @return list<array<string, mixed>>
     */
    public function collection(iterable $rules): array
    {
        return collect($rules)
            ->map(fn (SplitRule $rule): array => $this->make($rule))
            ->values()
            ->all();
    }

    /**
     * Só o campo do regime em uso vem preenchido; os outros saem nulos, e é
     * isso que impede a tela de ler peso numa regra percentual.
     *
     * @return list<array<string, mixed>>
     */
    private function entries(SplitRule $rule): array
    {
        return $rule->orderedEntries()
            ->map(fn (SplitEntry $entry): array => [
                'id' => $entry->id,
                'user_id' => $entry->user_id,
                'user_name' => $entry->user?->name,
                'weight' => $entry->weight,
                'percent' => $entry->percent,
                'fixed_amount' => $entry->fixed_amount,
            ])
            ->values()
            ->all();
    }
}
