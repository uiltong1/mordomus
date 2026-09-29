<?php

namespace Mordomus\Scheduling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Mordomus\Scheduling\Models\TriggerConfig;

class IndexTriggerConfigsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject_type' => ['sometimes', 'nullable', Rule::in(TriggerConfig::SUBJECT_TYPES)],
            'subject_id' => ['sometimes', 'nullable', 'string', 'size:26', 'required_with:subject_type'],
        ];
    }
}
