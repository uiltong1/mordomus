<?php

namespace Mordomus\Maintenance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShowAssetRequest extends FormRequest
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
