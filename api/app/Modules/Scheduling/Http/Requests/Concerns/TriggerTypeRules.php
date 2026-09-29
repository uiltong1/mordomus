<?php

namespace Mordomus\Scheduling\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * Campos por tipo de regra (TECHSPEC §6.1) e as regras que os exigem.
 *
 * Os campos que não pertencem ao tipo saem `prohibited`: uma regra `INTERVAL`
 * que carregue `day_of_month` guardado é lixo silencioso que volta a puzzling
 * quando alguém ler a linha do banco. O erro aponta o campo, que é o que o AC
 * de payload inválido pede.
 *
 * No patch os campos omitidos não são cobrados — a regra já os tem gravados.
 * Eles só passam a ser obrigatórios quando o próprio `type` vem no payload,
 * porque aí a linha muda de tipo e o conjunto novo precisa chegar inteiro.
 */
trait TriggerTypeRules
{
    /** Campos que cada tipo exige. */
    private static function requiredByType(): array
    {
        return [
            TriggerConfig::TYPE_INTERVAL => ['interval_value', 'interval_unit'],
            TriggerConfig::TYPE_CALENDAR_MONTHLY => ['day_of_month'],
            TriggerConfig::TYPE_POST_COMPLETION => ['interval_value', 'interval_unit', 'recalculate_base'],
            // `ESCALATED` é um `INTERVAL` com avisos: o intervalo diz de quanto
            // em quanto tempo o ciclo se repete, e os offsets só multiplicam os
            // avisos. Sem o intervalo a ocorrência nunca voltaria, e a regra
            // ficaria presa num único dia.
            TriggerConfig::TYPE_ESCALATED => ['interval_value', 'interval_unit', 'custom_offsets'],
        ];
    }

    /**
     * Validadores de cada campo, sem a exigência condicional.
     *
     * @return array<string, list<mixed>>
     */
    private static function typeValidators(): array
    {
        return [
            'interval_value' => ['integer', 'min:1', 'max:65535'],
            'interval_unit' => [Rule::in(TriggerConfig::INTERVAL_UNITS)],
            'day_of_month' => ['integer', 'between:1,31'],
            'recalculate_base' => [Rule::in([TriggerConfig::RECALCULATE_DUE_DATE, TriggerConfig::RECALCULATE_COMPLETION])],
            'custom_offsets' => ['array', 'min:1'],
            'custom_offsets.*' => ['integer', 'between:-365,365'],
        ];
    }

    /**
     * @param  bool  $typeChanged  o patch está trocando o tipo da regra
     * @return array<string, list<mixed>>
     */
    private static function typeRules(?string $type, bool $typeChanged): array
    {
        $required = self::requiredByType()[$type] ?? [];
        $rules = [];

        foreach (self::typeValidators() as $field => $validators) {
            // As regras curinga (`custom_offsets.*`) descrevem os itens de um
            // campo, não um campo do tipo: elas não entram na decisão de exigir
            // nem de proibir.
            if (str_contains($field, '*')) {
                continue;
            }

            $belongs = in_array($field, $required, true);

            $rules[$field] = match (true) {
                ! $belongs => ['prohibited'],
                $typeChanged => array_merge(['required'], $validators),
                default => array_merge(['sometimes'], $validators),
            };
        }

        if (in_array('custom_offsets', $required, true)) {
            $rules['custom_offsets.*'] = self::typeValidators()['custom_offsets.*'];
        }

        return $rules;
    }

    /**
     * Campos válidos em qualquer tipo.
     *
     * @param  bool  $partial  patch: os campos omitidos não são cobrados
     * @return array<string, list<mixed>>
     */
    private static function sharedRules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'title' => [$presence, 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'advance_notice_days' => ['sometimes', 'integer', 'between:0,365'],
            'preferred_hour' => ['sometimes', 'nullable', 'date_format:H:i'],
        ];
    }
}
