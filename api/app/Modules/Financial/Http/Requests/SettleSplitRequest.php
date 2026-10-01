<?php

namespace Mordomus\Financial\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Baixa da cota de um morador.
 *
 * `settled` é a única coisa que muda aqui: a cota já foi calculada, e o que o
 * dono da casa registra é quem já pagou a parte dele. O padrão é `true` porque
 * a operação que se repete é marcar como pago; desmarcar é a correção, e ela
 * vem explícita.
 *
 * A capability é `splits.manage`, e a baixa é de bookkeeping da casa: quem
 * anota precisa ser quem responde por ela.
 */
class SettleSplitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'string', 'size:26'],
            'settled' => ['sometimes', 'boolean'],
        ];
    }
}
