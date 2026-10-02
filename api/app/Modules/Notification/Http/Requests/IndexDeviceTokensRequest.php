<?php

namespace Mordomus\Notification\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Listagem das assinaturas do morador.
 *
 * Sem filtro: a lista é do chamador e cabe em few linhas. O FormRequest existe
 * pelo mesmo motivo que os outros da casa — a entrada do service é sempre um
 * request já resolvido, e não um `request()` pego dentro do controller.
 */
class IndexDeviceTokensRequest extends FormRequest
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
