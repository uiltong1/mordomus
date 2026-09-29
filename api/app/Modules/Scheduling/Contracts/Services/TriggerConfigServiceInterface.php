<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Contracts\Services;

use Illuminate\Http\Request;

/**
 * Regras de recorrência do módulo Scheduling.
 *
 * `$subjectType`/`$subjectId` vêm como argumento, e não lidos do corpo: a
 * rota direta os valida no FormRequest, e o atalho proxied do Maintenance
 * entrega o alvo pela própria URL. Assim os dois caminhos de entrada
 * compartilham a mesma regra sem duplicar a validação.
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
     * Atalho proxied: mesmo payload de `store`, alvo travado no ativo da URL.
     * Repetir o título atualiza a regra existente em vez de conflitar.
     *
     * @return array<string, mixed>
     */
    public function storeForAsset(Request $request, string $assetId): array;

    /** Calcula a próxima data sem persistir nada. @return array<string, mixed> */
    public function preview(Request $request): array;
}
