<?php

declare(strict_types=1);

namespace Mordomus\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexTenantsRequest extends FormRequest
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
