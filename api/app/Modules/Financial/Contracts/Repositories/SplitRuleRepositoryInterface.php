<?php

declare(strict_types=1);

namespace Mordomus\Financial\Contracts\Repositories;

use Illuminate\Support\Collection;
use Mordomus\Financial\Models\SplitRule;

interface SplitRuleRepositoryInterface
{
    public function list(string $tenantId): Collection;

    public function find(string $splitRuleId, ?string $tenantId): ?SplitRule;

    public function findForBill(string $tenantId, ?string $billId): ?SplitRule;

    public function findEffective(string $tenantId, ?string $billId): ?SplitRule;

    public function billIdsWithOwnRule(string $tenantId): array;

    public function create(array $attributes): SplitRule;

    public function change(SplitRule $rule, array $attributes): SplitRule;

    public function replaceEntries(SplitRule $rule, array $entries): SplitRule;
}
