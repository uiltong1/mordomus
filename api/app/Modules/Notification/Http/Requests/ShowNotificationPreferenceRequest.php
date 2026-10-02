<?php

namespace Mordomus\Notification\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Leitura das preferências do morador.
 *
 * Sem filtro e sem validação: o que a tela pede é "o que vale para mim", e a
 * precedência é do service. O FormRequest existe para a entrada ser sempre um
 * request resolvido, e não um `request()` pego dentro do controller.
 */
class ShowNotificationPreferenceRequest extends FormRequest
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
