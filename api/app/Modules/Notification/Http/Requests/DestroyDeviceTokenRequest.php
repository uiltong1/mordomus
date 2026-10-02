<?php

namespace Mordomus\Notification\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Desinscrição de uma assinatura.
 *
 * O alvo vem da query e não da rota porque o `endpoint` é a chave natural da
 * assinatura — é o que o navegador entrega ao desinscrever, e é o que o
 * serviço de push devolve quando a assinatura morreu. Nenhum dos dois é um id
 * que a tela precise carregar.
 */
class DestroyDeviceTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'max:2048'],
        ];
    }
}
