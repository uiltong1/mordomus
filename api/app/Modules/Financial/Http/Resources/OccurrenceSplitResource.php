<?php

namespace Mordomus\Financial\Http\Resources;

use Illuminate\Support\Carbon;
use Mordomus\Financial\Models\BillOccurrence;
use Mordomus\Financial\Models\SplitRule;

/**
 * Shape da divisão de um vencimento: a regra que a produziu e as cotas dela.
 *
 * `scope` diz o que `shares` contém — a divisão inteira ou só a cota de quem
 * perguntou. Sem esse campo a mesma lista teria dois formatos dependendo do
 * chamador, e a tela não teria como saber se está olhando o todo.
 *
 * `total` é sempre o valor do vencimento, e não a soma das cotas devolvidas:
 * em leitura restrita a soma da lista não fecha, e mostrar um total que não
 * bate com o que veio seria pior do que dizer que a lista é parcial.
 */
final class OccurrenceSplitResource
{
    /**
     * @param  list<array<string, mixed>>  $shares
     * @return array<string, mixed>
     */
    public function make(
        BillOccurrence $occurrence,
        SplitRule $rule,
        array $shares,
        string $scope,
        string $timezone,
    ): array {
        return [
            'bill_occurrence_id' => $occurrence->id,
            'bill_id' => $occurrence->bill_id,
            'bill_name' => $occurrence->bill?->name,
            'due_date' => $occurrence->dueDate(),
            'amount' => (string) $occurrence->amount,
            'status' => $occurrence->status,
            'split_rule_id' => $rule->id,
            'mode' => $rule->mode,
            'is_house_default' => $rule->isHouseDefault(),
            'total' => (string) $occurrence->amount,
            'scope' => $scope,
            'shares' => $this->withInstants($shares, $timezone),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $shares
     * @return list<array<string, mixed>>
     */
    private function withInstants(array $shares, string $timezone): array
    {
        return array_map(
            // `array_merge` e não `+`: o `+` manteria o valor da esquerda na
            // chave repetida, e o instante sairia no fuso do banco.
            fn (array $share): array => array_merge(
                $share,
                ['settled_at' => $this->format($share['settled_at'] ?? null, $timezone)],
            ),
            $shares,
        );
    }

    private function format(mixed $value, string $timezone): ?string
    {
        return $value === null
            ? null
            : Carbon::parse($value)->timezone($timezone)->toIso8601String();
    }
}
