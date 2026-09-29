<?php

namespace Mordomus\Maintenance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Mordomus\Scheduling\Http\Requests\Concerns\TriggerTypeRules;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * Regra de manutenção criada pelo atalho do card do ativo.
 *
 * Mesmo payload de `POST /trigger-configs`, sem `subject_type`/`subject_id`: o
 * alvo é o próprio ativo da URL. As regras condicionais por tipo são as do
 * módulo dono do payload — replicá-las aqui as deixaria divergir na primeira
 * mudança de tipo.
 */
class StoreAssetScheduleRequest extends FormRequest
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
        ] + self::sharedRules() + self::typeRules($type, typeChanged: true);
    }
}
