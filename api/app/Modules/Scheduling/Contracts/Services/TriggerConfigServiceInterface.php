<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Contracts\Services;

use Illuminate\Http\Request;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * Regras de recorrência do módulo Scheduling.
 *
 * `$subjectType`/`$subjectId` vêm como argumento, e não lidos do corpo: a
 * rota direta os valida no FormRequest, e o atalho proxied do Maintenance
 * entrega o alvo pela própria URL. Assim os dois caminhos de entrada
 * compartilham a mesma regra sem duplicar a validação.
 *
 * `storeForSubject`/`pauseForSubject` são a entrada de outro módulo (o
 * Financial, que cadastra a cadência da conta): não recebem Request porque o
 * chamador não é uma requisição HTTP, e continuam sendo este serviço a
 * única mão que escreve `trigger_configs` e calcula `next_due_at`.
 */
interface TriggerConfigServiceInterface
{
    /** @return array<string, mixed> */
    public function index(Request $request): array;

    /** @return array<string, mixed> */
    public function show(Request $request, string $triggerConfigId): array;

    /** @return array<string, mixed> */
    public function store(Request $request, string $subjectType, string $subjectId): array;

    /** @return array<string, mixed> */
    public function update(Request $request, string $triggerConfigId): array;

    /** @return array<string, mixed> */
    public function destroy(Request $request, string $triggerConfigId): array;

    /**
     * Cadastra a regra de um alvo a partir de atributos já decididos.
     *
     * Título repetido no mesmo alvo substitui a regra em vez de conflitar: o
     * chamador é uma formulário (o card do ativo, o cadastro da conta) que
     * reenvia a regra inteira a cada save, e a data é recalculada aqui.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function storeForSubject(string $tenantId, string $subjectType, string $subjectId, array $attributes): TriggerConfig;

    /**
     * Cadência única do alvo: a regra reaproveitada mesmo quando o título
     * muda.
     *
     * O título da regra de uma conta é o nome da conta, então renomear a
     * conta faria a busca por título errar e nascer uma segunda regra. Aqui o
     * alvo manda: existe uma regra viva, e a escrita atualiza essa.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function storeExclusiveForSubject(string $tenantId, string $subjectType, string $subjectId, array $attributes): TriggerConfig;

    /**
     * Pausa as regras do alvo sem apagá-las.
     *
     * A conta perder a cadência é um estado, não uma exclusão: o histórico de
     * vencimentos continua existindo e a regra volta a valer quando a conta
     * for reativada.
     */
    public function pauseForSubject(string $tenantId, string $subjectType, string $subjectId): int;

    /**
     * Retoma as regras pausadas do alvo, recalculando a data.
     *
     * Reativar não é o mesmo que gravar de novo: a regra guarda o dia e a
     * antecedência que a conta já tinha, e o que muda é só a vontade de
     * voltar a receber Those dates.
     */
    public function resumeForSubject(string $tenantId, string $subjectType, string $subjectId): int;

    /**
     * Atalho proxied: mesmo payload de `store`, alvo travado no ativo da URL.
     * Repetir o título atualiza a regra existente em vez de conflitar.
     *
     * @return array<string, mixed>
     */
    public function storeForAsset(Request $request, string $assetId): array;

    /** Calcula a próxima data sem persistir nada. @return array<string, mixed> */
    public function preview(Request $request): array;
}
