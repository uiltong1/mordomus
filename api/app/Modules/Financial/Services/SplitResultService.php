<?php

declare(strict_types=1);

namespace Mordomus\Financial\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Mordomus\Financial\Contracts\Repositories\BillOccurrenceRepositoryInterface;
use Mordomus\Financial\Contracts\Repositories\SplitResultRepositoryInterface;
use Mordomus\Financial\Contracts\Repositories\SplitRuleRepositoryInterface;
use Mordomus\Financial\Contracts\Services\SplitCalculatorInterface;
use Mordomus\Financial\Contracts\Services\SplitResultServiceInterface;
use Mordomus\Financial\Events\EventName;
use Mordomus\Financial\Exceptions\SplitNotComputable;
use Mordomus\Financial\Exceptions\SplitResultNotFound;
use Mordomus\Financial\Exceptions\SplitRuleNotFound;
use Mordomus\Financial\Http\Resources\OccurrenceSplitResource;
use Mordomus\Financial\Models\BillOccurrence;
use Mordomus\Financial\Models\SplitEntry;
use Mordomus\Financial\Models\SplitResult;
use Mordomus\Financial\Models\SplitRule;
use Mordomus\Http\Exceptions\TenantMismatch;
use Mordomus\Http\Tenancy\ActiveTenant;
use Mordomus\Scheduling\Contracts\Services\EventPublisherServiceInterface;
use Mordomus\Scheduling\Contracts\Services\TenantCalendarServiceInterface;

/**
 * Cota-parte dos vencimentos: materializa, consulta, dá baixa e recalcula.
 *
 * A materialização é a única escrita aqui. Ela roda em três momentos, e são
 * eles que cobrem toda mudança possível do valor dividido: quando o vencimento
 * nasce, quando o valor real da conta chega (conta variável só tem valor na
 * fatura, e é no pagamento que ele aparece) e quando a regra muda.
 *
 * A consulta não materializa. Um vencimento anterior a este recurso não tem
 * cota gravada, e a tela precisa responder mesmo assim — mas responder
 * calculando é diferente de responder gravando: leitura que escreve esconde o
 * efeito de um segundo acesso e faz o evento de aviso sair de um GET.
 */
final class SplitResultService implements SplitResultServiceInterface
{
    /** Leitura por quem administra a divisão. */
    private const SCOPE_ALL = 'all';

    /** Leitura por quem só pode ver a cota própria. */
    private const SCOPE_OWN = 'own';

    public function __construct(
        private readonly SplitRuleRepositoryInterface $rules,
        private readonly SplitResultRepositoryInterface $results,
        private readonly BillOccurrenceRepositoryInterface $occurrences,
        private readonly SplitCalculatorInterface $calculator,
        private readonly OccurrenceSplitResource $resource,
        private readonly TenantCalendarServiceInterface $calendar,
        private readonly EventPublisherServiceInterface $publisher,
    ) {}

    public function show(Request $request, string $billOccurrenceId): array
    {
        $occurrence = $this->occurrences->findOrFail($billOccurrenceId, $this->tenantId($request));

        return ['data' => $this->payload($request, $occurrence)];
    }

    public function settle(Request $request, string $billOccurrenceId): array
    {
        $occurrence = $this->occurrences->findOrFail($billOccurrenceId, $this->tenantId($request));
        $userId = $request->string('user_id')->toString();

        $result = $this->results->find((string) $occurrence->tenant_id, $occurrence->id, $userId)
            ?? throw SplitResultNotFound::make($occurrence->id, $userId);

        $this->results->settle(
            $result,
            $request->boolean('settled', true),
            CarbonImmutable::now('UTC')->toIso8601String(),
        );

        return ['data' => $this->payload($request, $occurrence)];
    }

    public function compute(BillOccurrence $occurrence): Collection
    {
        $tenantId = (string) $occurrence->tenant_id;
        $rule = $this->rules->findEffective($tenantId, $occurrence->bill_id);

        // Regra que sumiu não é regra com cota zero: as linhas do vencimento
        // saem, para que a lista da casa não mostre uma divisão que ela mesma
        // desfez.
        if ($rule === null) {
            $this->results->forget($tenantId, $occurrence->id);

            return new Collection;
        }

        try {
            $shares = $this->calculator->shares($rule, $rule->orderedEntries()->all(), (string) $occurrence->amount);
        } catch (SplitNotComputable $failure) {
            // A divisão é acessória à conta: uma regra que não fecha o valor
            // deste vencimento não pode derrubar o vencimento, que a casa
            // precisa pagar de qualquer jeito. A recusa honesta fica na leitura
            // da divisão, com o número no detalhe, e aqui fica o log.
            Log::warning('financial.split_not_computable', [
                'tenant_id' => $tenantId,
                'bill_occurrence_id' => $occurrence->id,
                'split_rule_id' => $rule->id,
                'mode' => $rule->mode,
                'reason' => $failure->details()['reason'] ?? null,
            ]);

            $this->results->forget($tenantId, $occurrence->id);

            return new Collection;
        }

        $before = $this->fingerprint($this->results->forOccurrence($tenantId, $occurrence->id));
        $saved = $this->results->sync($tenantId, $occurrence->id, $shares);

        // O aviso sai quando o número muda, e não quando o cálculo roda: regra
        // salva sem diferença real, ou a mesma materialização vista duas vezes,
        // não podem virar duas notificações da mesma conta.
        if ($before !== $this->fingerprint($saved)) {
            $this->announce($occurrence, $rule, $saved);
        }

        return $saved;
    }

    public function recalculateForRule(string $tenantId, SplitRule $rule): int
    {
        $ownBills = $rule->isHouseDefault() ? $this->rules->billIdsWithOwnRule($tenantId) : [];
        $recalculated = 0;

        foreach ($this->occurrences->open($tenantId, $rule->bill_id) as $occurrence) {
            // A regra geral não governa a conta que a casa ajustou: reescrever a
            // divisão de um aluguel com regra própria apagaria o combinado que o
            // dono da casa fez.
            if (in_array($occurrence->bill_id, $ownBills, true)) {
                continue;
            }

            $this->compute($occurrence);
            $recalculated++;
        }

        return $recalculated;
    }

    /**
     * Resposta da divisão de um vencimento.
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request, BillOccurrence $occurrence): array
    {
        $tenantId = (string) $occurrence->tenant_id;
        $timezone = $this->calendar->timezone($tenantId);
        $rule = $this->rules->findEffective($tenantId, $occurrence->bill_id)
            ?? throw SplitRuleNotFound::make([
                'bill_occurrence_id' => $occurrence->id,
                'bill_id' => $occurrence->bill_id,
            ]);

        $results = $this->results->forOccurrence($tenantId, $occurrence->id);
        $names = $this->names($rule);

        $shares = $results->isEmpty()
            ? $this->derive($rule, $occurrence, $names)
            : $results
                ->map(fn (SplitResult $result): array => [
                    'user_id' => (string) $result->user_id,
                    'user_name' => $names[(string) $result->user_id] ?? null,
                    'share_amount' => (string) $result->share_amount,
                    'settled' => $result->isSettled(),
                    'settled_at' => $result->settled_at,
                ])
                ->values()
                ->all();

        $every = $this->seesEveryShare($request);

        return $this->resource->make(
            $occurrence,
            $rule,
            $every
                ? $shares
                : array_values(array_filter(
                    $shares,
                    fn (array $share): bool => $share['user_id'] === (string) $request->user()?->id,
                )),
            $every ? self::SCOPE_ALL : self::SCOPE_OWN,
            $timezone,
        );
    }

    /**
     * Cotas de um vencimento que nunca foram materializadas.
     *
     * O cálculo é o mesmo e a resposta é a mesma; o que não acontece é a
     * gravação, porque a tela não pediu para mudar nada.
     *
     * @param  array<string, ?string>  $names
     * @return list<array<string, mixed>>
     */
    private function derive(SplitRule $rule, BillOccurrence $occurrence, array $names): array
    {
        $shares = $this->calculator->shares($rule, $rule->orderedEntries()->all(), (string) $occurrence->amount);

        return array_map(fn (array $share): array => [
            'user_id' => $share['user_id'],
            'user_name' => $names[$share['user_id']] ?? null,
            'share_amount' => $share['amount'],
            'settled' => false,
            'settled_at' => null,
        ], $shares);
    }

    /**
     * @return array<string, ?string>
     */
    private function names(SplitRule $rule): array
    {
        return $rule->orderedEntries()
            ->mapWithKeys(fn (SplitEntry $entry): array => [(string) $entry->user_id => $entry->user?->name])
            ->all();
    }

    /**
     * Quem enxerga a divisão inteira.
     *
     * A autorização do endpoint é do `FormRequest`; a pergunta daqui é outra —
     * de quais linhas a resposta pode ser montada. Quem administra a divisão
     * vê todas as cotas porque precisa fechar a conta da casa; quem só tem
     * leitura da própria vê a sua, e o critério de aceite do split é esse.
     */
    private function seesEveryShare(Request $request): bool
    {
        return (bool) $request->user()?->can('splits.manage');
    }

    /**
     * @param  Collection<int, SplitResult>  $results
     */
    private function announce(BillOccurrence $occurrence, SplitRule $rule, Collection $results): void
    {
        $tenantId = (string) $occurrence->tenant_id;

        $shares = $results
            ->map(fn (SplitResult $result): array => [
                'user_id' => (string) $result->user_id,
                'share_amount' => (string) $result->share_amount,
                'settled' => $result->isSettled(),
            ])
            ->values()
            ->all();

        $this->publisher->publish(EventName::EXPENSE_SPLIT_COMPUTED, $tenantId, [
            'tenant_id' => $tenantId,
            'bill_occurrence_id' => $occurrence->id,
            'bill_id' => $occurrence->bill_id,
            'due_date' => $occurrence->dueDate(),
            'amount' => (string) $occurrence->amount,
            'mode' => $rule->mode,
            'shares' => $shares,
            'dedupe_key' => sprintf(
                'expense.split_computed:%s:%s',
                $occurrence->id,
                substr($this->fingerprint($results), 0, 16),
            ),
        ]);

        Log::info('financial.split_computed', [
            'tenant_id' => $tenantId,
            'bill_occurrence_id' => $occurrence->id,
            'split_rule_id' => $rule->id,
            'mode' => $rule->mode,
            'shares' => count($shares),
        ]);
    }

    /**
     * Resumo canônico das cotas, para comparar e para deduplicar.
     *
     * A comparação é pelo conjunto, não pela ordem: quem recalcula pode
     * receber as entradas em outra sequência, e um aviso de recomputação sem
     * mudança seria o mesmo aviso de novo.
     *
     * @param  Collection<int, SplitResult>  $results
     */
    private function fingerprint(Collection $results): string
    {
        $lines = $results
            ->map(fn (SplitResult $result): string => implode('|', [
                (string) $result->user_id,
                (string) $result->share_amount,
                $result->isSettled() ? '1' : '0',
            ]))
            ->sort()
            ->values()
            ->all();

        return hash('sha256', implode("\n", $lines));
    }

    private function tenantId(Request $request): string
    {
        // O middleware `tenant` já devolveu 403 sem `tid`; o guard serve para
        // o service nunca receber um id vazio e vazar escopo.
        return ActiveTenant::id($request) ?? throw TenantMismatch::make();
    }
}
