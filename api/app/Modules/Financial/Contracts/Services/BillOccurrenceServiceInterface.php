<?php

declare(strict_types=1);

namespace Mordomus\Financial\Contracts\Services;

use Illuminate\Http\Request;

/**
 * Vencimentos: consulta, lançamento manual e baixa de pagamento.
 */
interface BillOccurrenceServiceInterface
{
    /** @return array<string, mixed> */
    public function index(Request $request): array;

    /**
     * Lançamento manual de vencimento — o caminho da conta variável, em que o
     * valor e o dia só existem depois que a conta chega.
     *
     * @return array<string, mixed>
     */
    public function store(Request $request): array;

    /**
     * Baixa de pagamento, idempotente: marcar duas vezes devolve o mesmo
     * lançamento e não abre um segundo.
     *
     * @return array<string, mixed>
     */
    public function pay(Request $request, string $billOccurrenceId): array;
}
