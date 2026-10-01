<?php

declare(strict_types=1);

namespace Mordomus\Financial\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mordomus\Financial\Contracts\Repositories\BillRepositoryInterface;
use Mordomus\Financial\Contracts\Repositories\SplitRuleRepositoryInterface;
use Mordomus\Financial\Contracts\Services\MoneyServiceInterface;
use Mordomus\Financial\Contracts\Services\SplitResultServiceInterface;
use Mordomus\Financial\Contracts\Services\SplitRuleServiceInterface;
use Mordomus\Financial\Http\Requests\IndexSplitRulesRequest;
use Mordomus\Financial\Http\Resources\SplitRuleResource;
use Mordomus\Financial\Models\SplitRule;
use Mordomus\Http\Exceptions\TenantMismatch;
use Mordomus\Http\Tenancy\ActiveTenant;

/**
 * Regras de divisão: cadastro e o recálculo que a mudança de regra obriga.
 *
 * A regra é identificada pela conta, e não por id: a tela abre a divisão de
 * uma conta e salva o que mudou, e um `PUT` com id exigiria que a tela descobrisse
 * antes qual id a conta já tinha. A conta ausente (ou nula) é a regra padrão da
 * casa, que vale para tudo que não tem regra própria — e é o índice único do
 * banco que garante que só existe uma delas.
 *
 * Salvar a regra refaz as cotas dos vencimentos em aberto que ela governa. É o
 * critério de aceite do split: o morador que mudou o combinado precisa ver a
 * cota nova na conta que ainda está por pagar, sem esperar alguém abrir a tela
 * de novo.
 */
final class SplitRuleService implements SplitRuleServiceInterface
{
    public function __construct(
        private readonly SplitRuleRepositoryInterface $rules,
        private readonly BillRepositoryInterface $bills,
        private readonly SplitResultServiceInterface $splits,
        private readonly SplitRuleResource $resource,
        private readonly MoneyServiceInterface $money,
    ) {}

    public function index(Request $request): array
    {
        $tenantId = $this->tenantId($request);
        $billId = $request instanceof IndexSplitRulesRequest ? $request->billId() : null;

        $rules = $this->rules->list($tenantId)
            ->when(
                $billId !== null,
                fn (Collection $all) => $all->filter(
                    fn (SplitRule $rule): bool => $rule->bill_id === $billId,
                )->values(),
            );

        return ['data' => $this->resource->collection($rules)];
    }

    public function update(Request $request): array
    {
        $tenantId = $this->tenantId($request);
        $billId = $this->billId($request, $tenantId);
        $mode = $request->string('mode')->toString();

        $rule = DB::transaction(function () use ($tenantId, $billId, $mode, $request): SplitRule {
            $attributes = [
                'mode' => $mode,
                'is_active' => $request->has('is_active') ? $request->boolean('is_active') : true,
            ];

            $rule = $this->rules->findForBill($tenantId, $billId)
                ?? $this->rules->create(['tenant_id' => $tenantId, 'bill_id' => $billId] + $attributes);

            $this->rules->change($rule, $attributes);

            return $this->rules->replaceEntries($rule, $this->entries($request, $mode));
        });

        $recalculated = $this->splits->recalculateForRule($tenantId, $rule);

        Log::info('financial.split_rule_saved', [
            'tenant_id' => $tenantId,
            'split_rule_id' => $rule->id,
            'bill_id' => $rule->bill_id,
            'mode' => $rule->mode,
            'entries' => $rule->entries->count(),
            'recalculated_occurrences' => $recalculated,
        ]);

        return ['data' => $this->resource->make($rule)];
    }

    /**
     * Conta da regra, ou `null` para a regra padrão da casa.
     *
     * A conta é procurada dentro da residência ativa: um id de outra casa
     * responderia 404 aqui em vez de criar uma regra apontando para ela.
     */
    private function billId(Request $request, string $tenantId): ?string
    {
        $billId = $request->input('bill_id');

        if (! is_string($billId) || $billId === '') {
            return null;
        }

        return $this->bills->findOrFail($billId, $tenantId)->id;
    }

    /**
     * Participantes no formato do regime, com o campo que não vale zerado em
     * `null`.
     *
     * A conversão passa pelo `MoneyService` para gravar o mesmo texto que o
     * cálculo lê de volta: peso e percentual também têm duas casas, e é
     * nelas que mora a diferença entre "um terço" e "33,33% de 100,00".
     *
     * @return list<array{user_id: string, weight: ?string, percent: ?string, fixed_amount: ?string}>
     */
    private function entries(Request $request, string $mode): array
    {
        $entries = $request->input('entries', []);

        return array_map(
            fn (array $entry): array => [
                'user_id' => (string) $entry['user_id'],
                'weight' => $mode === SplitRule::MODE_WEIGHTED ? $this->decimal($entry['weight'] ?? null) : null,
                'percent' => $mode === SplitRule::MODE_PERCENT ? $this->decimal($entry['percent'] ?? null) : null,
                'fixed_amount' => $mode === SplitRule::MODE_CUSTOM ? $this->decimal($entry['fixed_amount'] ?? null) : null,
            ],
            is_array($entries) ? array_values($entries) : [],
        );
    }

    private function decimal(mixed $value): ?string
    {
        $amount = is_string($value) || is_numeric($value) ? (string) $value : null;

        return $amount === null ? null : $this->money->fromCents($this->money->cents($amount));
    }

    private function tenantId(Request $request): string
    {
        // O middleware `tenant` já devolveu 403 sem `tid`; o guard serve para
        // o service nunca receber um id vazio e vazar escopo.
        return ActiveTenant::id($request) ?? throw TenantMismatch::make();
    }
}
