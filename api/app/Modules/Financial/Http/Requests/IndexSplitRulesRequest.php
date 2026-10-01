<?php

namespace Mordomus\Financial\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtro da lista de regras de divisão.
 *
 * `bill_id` responde à pergunta que a tela de uma conta faz — "esta conta está
 * dividida como?" — sem obrigar a tela a varrer a casa inteira para achar. Sem
 * o filtro, a lista traz a regra padrão da casa junto com a da conta, e são
 * duas regras valendo ao mesmo tempo para quem lê a resposta.
 */
class IndexSplitRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'bill_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ];
    }

    /** Conta filtrada; null devolve todas as regras da residência. */
    public function billId(): ?string
    {
        $billId = $this->input('bill_id');

        return is_string($billId) && $billId !== '' ? $billId : null;
    }
}
