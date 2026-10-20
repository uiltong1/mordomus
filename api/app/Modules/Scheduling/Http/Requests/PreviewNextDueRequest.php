<?php

namespace Mordomus\Scheduling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Mordomus\Scheduling\Http\Requests\Concerns\TriggerTypeRules;
use Mordomus\Scheduling\Models\TriggerConfig;

class PreviewNextDueRequest extends FormRequest
{
    use TriggerTypeRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $type = $this->string('type')->toString();

        return [
            'type' => ['required', Rule::in(TriggerConfig::TYPES)],
        ] + self::typeRules($type, typeChanged: true) + [

            'base' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'preferred_hour' => ['sometimes', 'nullable', 'date_format:H:i'],
        ];
    }
}
