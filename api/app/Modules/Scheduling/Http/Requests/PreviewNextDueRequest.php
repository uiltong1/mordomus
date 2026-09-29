<?php

namespace Mordomus\Scheduling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Mordomus\Scheduling\Http\Requests\Concerns\TriggerTypeRules;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * Cálculo sem persistir, para o formulário do front mostrar a próxima data
 * enquanto a regra ainda está sendo montada.
 *
 * Não pede alvo: a matemática da data não depende do ativo nem da conta, e
 * obrigar o alvo aqui só acrescentaria uma etapa ao preenchimento.
 */
class PreviewNextDueRequest extends FormRequest
{
    use TriggerTypeRules;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $type = $this->string('type')->toString();

        return [
            'type' => ['required', Rule::in(TriggerConfig::TYPES)],
        ] + self::typeRules($type, typeChanged: true) + [
            // Âncora do ciclo para simular "e se o último cumprimento foi dia X?".
            'base' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'preferred_hour' => ['sometimes', 'nullable', 'date_format:H:i'],
        ];
    }
}
