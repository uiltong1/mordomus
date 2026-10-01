<?php

declare(strict_types=1);

namespace Mordomus\Financial\Contracts\Repositories;

use Illuminate\Support\Collection;
use Mordomus\Financial\Models\SplitRule;

interface SplitRuleRepositoryInterface
{
    /** @return Collection<int, SplitRule> */
    public function list(string $tenantId): Collection;

    public function find(string $splitRuleId, ?string $tenantId): ?SplitRule;

    /**
     * Rega gravada para uma conta, seja ela ativa ou não.
     *
     * @param  string|null  $billId  null = a regra padrão da casa
     */
    public function findForBill(string $tenantId, ?string $billId): ?SplitRule;

    /**
     * Rega que vale para uma conta: a da própria conta quando existe e está
     * ativa, senão a padrão da casa.
     *
     * @param  string|null  $billId  conta do vencimento; null = regra padrão
     */
    public function findEffective(string $tenantId, ?string $billId): ?SplitRule;

    /**
     * Contas da residência que têm regra de divisão própria.
     *
     * Serve para o recálculo do padrão da casa saber o que não é dele: regra
     * geral não pode reescrever a divisão de uma conta que a casa ajustou.
     *
     * @return list<string>
     */
    public function billIdsWithOwnRule(string $tenantId): array;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): SplitRule;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function change(SplitRule $rule, array $attributes): SplitRule;

    /**
     * Substitui os participantes da regra inteira.
     *
     * @param  list<array{user_id: string, weight: ?string, percent: ?string, fixed_amount: ?string}>  $entries
     */
    public function replaceEntries(SplitRule $rule, array $entries): SplitRule;
}
