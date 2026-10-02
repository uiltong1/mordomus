<?php

namespace Mordomus\Notification\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Mordomus\Notification\Models\NotificationPreference;

/**
 * Quiet hours, digest e horário preferido do morador.
 *
 * Os três campos são independentes e todos opcionais: quem muda só a janela
 * deixa o digest como estava. O que não pode é uma janela com um lado só —
 * meia janela não existe, e gravar `quiet_start` sem `quiet_end` deixaria o
 * morador sem silêncio nenhum sem ele ter pedido isso.
 *
 * `digest` é a escolha do canal: `instant` é push, `daily` é e-mail no
 * horário preferido. Não há interruptor por canal no contrato, e há o porquê
 * do jeito que está.
 */
class UpdateNotificationPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'quiet_start' => ['sometimes', 'nullable', 'date_format:H:i'],
            'quiet_end' => ['sometimes', 'nullable', 'date_format:H:i'],
            'digest' => ['sometimes', 'nullable', Rule::in(NotificationPreference::DIGESTS)],
            'preferred_hour' => ['sometimes', 'nullable', 'date_format:H:i'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->filled('quiet_start') xor $this->filled('quiet_end')) {
                    $validator->errors()->add(
                        'quiet_end',
                        'A janela de silêncio precisa dos dois lados: início e fim.',
                    );
                }
            },
        ];
    }
}
