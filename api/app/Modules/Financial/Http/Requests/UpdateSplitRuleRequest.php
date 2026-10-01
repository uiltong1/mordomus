<?php

namespace Mordomus\Financial\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Mordomus\Financial\Http\Requests\Concerns\SplitModeRules;
use Mordomus\Financial\Models\SplitRule;
use Mordomus\Http\Tenancy\ActiveTenant;

/**
 * Regra de divisão de uma conta — ou da casa, quando `bill_id` vem nulo.
 *
 * É o PUT da regra, e não um PATCH com id: quem salva é a tela de uma conta, e
 * ela sabe de qual conta está falando, não qual id a regra já tinha. O
 * `bill_id` ausente é a regra padrão da casa, que vale para o que não tem
 * regra própria.
 *
 * `entries` é o conjunto completo, não um incremento: a soma das cotas fecha
 * com o total (regra R5), e um patch parcial deixaria a regra com metade dos
 * moradores e o cálculo verdadeiro sem eles.
 */
class UpdateSplitRuleRequest extends FormRequest
{
    use SplitModeRules;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $mode = $this->string('mode')->toString();

        return [
            'bill_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'mode' => ['required', Rule::in(SplitRule::MODES)],
            'is_active' => ['sometimes', 'boolean'],
            'entries' => ['required', 'array', 'min:1', 'max:50'],
            'entries.*.user_id' => [
                'required',
                'string',
                'size:26',
                'distinct',
                ...$this->participantsOf(ActiveTenant::id($this)),
            ],
        ] + self::modeRules($mode);
    }
}
