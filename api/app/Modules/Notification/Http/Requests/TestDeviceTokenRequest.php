<?php

namespace Mordomus\Notification\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Envio de aviso de teste para as assinaturas do morador.
 *
 * Não há nada para validar no corpo: o alvo é quem chama, e o que decide se o
 * aviso pode sair é o ambiente ter par VAPID — o que o service responde com
 * `503`, e não um `422` de entrada.
 */
class TestDeviceTokenRequest extends FormRequest
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
