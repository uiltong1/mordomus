<?php

namespace Mordomus\Scheduling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Mordomus\Scheduling\Http\Requests\Concerns\TriggerTypeRules;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * Patch da regra.
 *
 * O tipo efetivo vem do payload quando presente e da linha gravada quando
 * omitido — sem isso, validar as condições de `INTERVAL` contra um
 * `CALENDAR_MONTHLY` já gravado reprovaria um patch que só muda o título.
 */
class UpdateTriggerConfigRequest extends FormRequest
{
    use TriggerTypeRules;

    /** Campos que o patch aceita; ao menos um precisa vir. */
    private const PATCHABLE = [
        'type', 'title', 'description', 'is_active', 'advance_notice_days', 'preferred_hour',
        'interval_value', 'interval_unit', 'day_of_month', 'recalculate_base', 'custom_offsets',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $payloadType = $this->filled('type') ? $this->string('type')->toString() : null;
        $storedType = $this->storedType();

        return [
            'type' => ['sometimes', Rule::in(TriggerConfig::TYPES)],
        ] + self::sharedRules(partial: true) + self::typeRules(
            $payloadType ?? $storedType,
            $payloadType !== null && $payloadType !== $storedType,
        );
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $provided = array_filter(self::PATCHABLE, fn (string $field): bool => $this->has($field));

                if ($provided === []) {
                    $validator->errors()->add('_', 'Informe ao menos um campo para atualizar.');
                }
            },
        ];
    }

    /** Tipo já gravado, lido dentro da residência ativa. */
    private function storedType(): ?string
    {
        $id = $this->route('triggerConfig');

        if (! is_string($id) || $id === '') {
            return null;
        }

        // Já dentro da residência ativa: o escopo global está ativo neste
        // ponto do ciclo, então a leitura é a mesma que o service fará.
        return TriggerConfig::query()->whereKey($id)->value('type');
    }
}
