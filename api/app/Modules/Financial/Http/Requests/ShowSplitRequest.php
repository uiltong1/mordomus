<?php

namespace Mordomus\Financial\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Mordomus\Http\Exceptions\Forbidden;

/**
 * Leitura da divisão de um vencimento.
 *
 * A capability entra aqui em vez de no middleware porque a condição é "ou", e
 * o middleware de capability exige todas as capabilities listadas na rota.
 * Quem administra a divisão e quem só consulta a própria cota leem o mesmo
 * endpoint; o que muda é o recorte da resposta, e esse recorte está em
 * `seesEveryShare`.
 *
 * O erro sai por `failedAuthorization` para ser o mesmo envelope que o
 * middleware produziria — 403 com o mesmo código, e não a página de login que
 * o Laravel devolveria por padrão numa requisição de API.
 */
class ShowSplitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->canManage() || $this->canViewOwn();
    }

    protected function failedAuthorization(): void
    {
        throw Forbidden::make();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    /** O chamador enxerga a divisão inteira, e não só a cota dele. */
    public function seesEveryShare(): bool
    {
        return $this->canManage();
    }

    private function canManage(): bool
    {
        return (bool) $this->user()?->can('splits.manage');
    }

    private function canViewOwn(): bool
    {
        return (bool) $this->user()?->can('splits.view_own');
    }
}
