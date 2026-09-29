<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Contracts\Services;

use Illuminate\Http\Request;

/**
 * Agenda da residência: consulta, check-in e dispensa.
 *
 * `$subjectType` trava o tipo de alvo esperado e existe para o atalho do
 * Maintenance, cujo card é de um ativo: uma ocorrência de conta não é o
 * check-in de um ativo, e a resposta é 404 nos dois casos — a existência da
 * ocorrência não é informação de quem está no card de outro alvo.
 */
interface OccurrenceServiceInterface
{
    /** @return array<string, mixed> */
    public function index(Request $request): array;

    /**
     * Conclui a ocorrência e enfileira o recálculo do ciclo.
     *
     * Idempotente (R3): concluir duas vezes devolve a mesma ocorrência e não
     * cria um segundo ciclo.
     *
     * @return array<string, mixed>
     */
    public function complete(Request $request, string $occurrenceId, ?string $subjectType = null): array;

    /** @return array<string, mixed> */
    public function skip(Request $request, string $occurrenceId, ?string $subjectType = null): array;
}
