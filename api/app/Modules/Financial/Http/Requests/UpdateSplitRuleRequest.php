<?php

namespace Mordomus\Financial\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Mordomus\Financial\Http\Requests\Concerns\SplitModeRules;
use Mordomus\Financial\Models\SplitRule;
use Mordomus\Http\Tenancy\ActiveTenant;

class UpdateSplitRuleRequest extends FormRequest
{
    use SplitModeRules;

    public function authorize(): bool
    {
        return true;
    }

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
