<?php

namespace Mordomus\Maintenance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexAssetsRequest extends FormRequest
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
