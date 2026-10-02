<?php

namespace Mordomus\Notification\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Mordomus\Notification\Models\DeviceToken;

/**
 * Assinatura de Web Push do morador (ADR-001).
 *
 * `p256dh` e `auth` são a chave pública e o segredo de autenticação da
 * assinatura, e é com eles que o corpo da notificação é cifrado. A validação
 * `base64url` é o que separa "o navegador mandou a assinatura" de "o
 * navegador mandou lixo" — e o corpo só é cifrado depois dela.
 *
 * `endpoint` tem teto de 2048 porque é o que vira chave única e o que o
 * serviço de push devolve na resposta; um endpoint de MB não é assinatura, é
 * payload.
 */
class StoreDeviceTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'platform' => ['sometimes', Rule::in(DeviceToken::PLATFORMS)],
            'endpoint' => ['required', 'url', 'max:2048'],
            'p256dh' => ['required', 'string', 'max:256', 'regex:/^[A-Za-z0-9\-_=]+$/'],
            'auth' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-_=]+$/'],
        ];
    }
}
