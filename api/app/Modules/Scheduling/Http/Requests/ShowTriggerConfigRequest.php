<?php

namespace Mordomus\Scheduling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShowTriggerConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
