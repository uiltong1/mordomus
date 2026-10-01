<?php

namespace Mordomus\Financial\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Mordomus\Financial\Models\SplitRule;

/**
 * Campos de cada participante por regime de divisão.
 *
 * Um morador que preenche peso numa regra percentual deixaria um número no
 * cadastro que nada lê e que volta como puzzling quando alguém abre a linha do
 * banco. Por isso o campo que o regime não usa sai `prohibited`: o erro aponta
 * o campo, que é o que a tela precisa para pintar a caixa certa.
 */
trait SplitModeRules
{
    /** Campo que o regime exige de cada participante. */
    private static function requiredByMode(): array
    {
        return [
            SplitRule::MODE_WEIGHTED => 'weight',
            SplitRule::MODE_PERCENT => 'percent',
            SplitRule::MODE_CUSTOM => 'fixed_amount',
        ];
    }

    /**
     * Validadores de cada campo, sem a exigência condicional.
     *
     * @return array<string, list<mixed>>
     */
    private static function modeValidators(): array
    {
        return [
            'weight' => ['numeric', 'min:0.01', 'max:9999.99'],
            'percent' => ['numeric', 'min:0.01', 'max:999.99'],
            'fixed_amount' => ['numeric', 'min:0', 'max:9999999999.99'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private static function modeRules(string $mode): array
    {
        $required = self::requiredByMode()[$mode] ?? null;
        $rules = [];

        foreach (self::modeValidators() as $field => $validators) {
            $rules['entries.*.'.$field] = $field === $required
                ? array_merge(['required'], $validators)
                : ['prohibited'];
        }

        return $rules;
    }

    /**
     * Morador ativo da residência ativa.
     *
     * A regra existe para a casa, e uma regra que aponta para quem não mora
     * mais nela divide a conta com alguém que nem vê a conta.
     *
     * @return list<mixed>
     */
    private function participantsOf(?string $tenantId): array
    {
        return [Rule::exists('memberships', 'user_id')->where(
            fn ($query) => $query->where('tenant_id', $tenantId)->where('status', 'active'),
        )];
    }
}
