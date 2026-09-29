<?php

namespace Mordomus\Scheduling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Check-in de conclusão e dispensa não recebem payload: o alvo e a ocorrência
 * vêm da rota, e a autorização é a capability do middleware.
 */
class CompleteOccurrenceRequest extends FormRequest
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
