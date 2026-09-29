<?php

namespace Mordomus\Scheduling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Mordomus\Scheduling\Http\Requests\Concerns\TriggerTypeRules;
use Mordomus\Scheduling\Models\TriggerConfig;

class StoreTriggerConfigRequest extends FormRequest
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
            'subject_type' => ['required', Rule::in(TriggerConfig::SUBJECT_TYPES)],
            'subject_id' => ['required', 'string', 'size:26'],
            'type' => ['required', Rule::in(TriggerConfig::TYPES)],
        ] + self::sharedRules() + self::typeRules($type, typeChanged: true);
    }
}
