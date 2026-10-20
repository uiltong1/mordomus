<?php

namespace Mordomus\Maintenance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Mordomus\Scheduling\Http\Requests\Concerns\TriggerTypeRules;
use Mordomus\Scheduling\Models\TriggerConfig;

class StoreAssetScheduleRequest extends FormRequest
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
        ] + self::sharedRules() + self::typeRules($type, typeChanged: true);
    }
}
