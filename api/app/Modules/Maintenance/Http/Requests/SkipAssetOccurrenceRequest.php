<?php

namespace Mordomus\Maintenance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Check-in de conclusão e dispensa pelo card do ativo não recebem payload: o
 * alvo é o ativo da URL e a ocorrência vem do caminho, e a autorização é a
 * capability do middleware.
 */
class SkipAssetOccurrenceRequest extends FormRequest
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
