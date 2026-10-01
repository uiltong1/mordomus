<?php

declare(strict_types=1);

namespace Mordomus\Financial\Services;

use Illuminate\Http\Request;
use Mordomus\Financial\Contracts\Repositories\BillOccurrenceRepositoryInterface;
use Mordomus\Financial\Contracts\Repositories\BillRepositoryInterface;
use Mordomus\Financial\Contracts\Services\BillServiceInterface;
use Mordomus\Financial\Contracts\Services\MoneyServiceInterface;
use Mordomus\Financial\Http\Resources\BillResource;
use Mordomus\Financial\Models\Bill;
use Mordomus\Http\Exceptions\TenantMismatch;
use Mordomus\Http\Pagination\OffsetPagination;
use Mordomus\Http\Tenancy\ActiveTenant;
use Mordomus\Scheduling\Contracts\Services\TenantCalendarServiceInterface;
use Mordomus\Scheduling\Contracts\Services\TriggerConfigServiceInterface;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * Cadastro das contas e a cadência que cada uma pede ao Scheduling.
 *
 * `due_day` e `advance_notice_days` não viram colunas aqui: viram uma regra
 * `CALENDAR_MONTHLY` no módulo dono do motor de tempo, que calcula a primeira
 * data e materializa os vencimentos (regra R7). A conta guarda o que a casa
 * paga; o Scheduling guarda de quando em quando.
 */
final class BillService implements BillServiceInterface
{
    public function __construct(
        private readonly BillRepositoryInterface $bills,
        private readonly BillOccurrenceRepositoryInterface $occurrences,
        private readonly BillResource $resource,
        private readonly MoneyServiceInterface $money,
        private readonly TenantCalendarServiceInterface $calendar,
        private readonly TriggerConfigServiceInterface $triggers,
    ) {}

    public function index(Request $request): array
    {
        $tenantId = $this->tenantId($request);
        $timezone = $this->calendar->timezone($tenantId);
        $pagination = OffsetPagination::from($request);

        $bills = $this->bills->paginate(
            $this->optionalString($request->query('kind')),
            $request->has('is_active') ? $request->boolean('is_active') : null,
            $this->optionalString($request->query('category')),
            $pagination,
        );

        return [
            'data' => $this->resource->collection($bills->getCollection(), $timezone),
            'meta' => $pagination->meta($bills->total(), $bills->lastPage()),
        ];
    }

    public function show(Request $request, string $billId): array
    {
        $tenantId = $this->tenantId($request);

        return ['data' => $this->resource->make(
            $this->find($request, $billId),
            $this->calendar->timezone($tenantId),
        )];
    }

    public function store(Request $request): array
    {
        $tenantId = $this->tenantId($request);
        $timezone = $this->calendar->timezone($tenantId);

        $bill = $this->bills->create([
            'is_active' => $request->boolean('is_active', true),
            'currency' => $this->currency($request),
            ...$this->attributes($request),
            'created_by' => (string) $request->user()->id,
        ]);

        $this->syncSchedule($request, $bill, $tenantId);

        return ['data' => $this->resource->make($this->reload($bill, $tenantId), $timezone)];
    }

    public function update(Request $request, string $billId): array
    {
        $tenantId = $this->tenantId($request);
        $timezone = $this->calendar->timezone($tenantId);
        $bill = $this->find($request, $billId);

        $bill = $this->bills->change($bill, $this->attributes($request));

        $this->syncSchedule($request, $bill, $tenantId);
        $this->dropUpcomingWhenDeactivated($request, $bill, $tenantId);

        return ['data' => $this->resource->make($this->reload($bill, $tenantId), $timezone)];
    }

    /**
     * Cadência da conta, escrita pelo módulo Scheduling.
     *
     * A conta tem uma única cadência, e ela acompanha o nome: o título da
     * regra é o nome da conta (é ele que aparece na agenda), e a escrita
     * reaproveita a regra existente em vez de nascer outra a cada renomeação.
     */
    private function syncSchedule(Request $request, Bill $bill, string $tenantId): void
    {
        $cadence = $request->has('due_day');
        $stateChanged = $request->has('is_active');

        if (! $cadence && ! $stateChanged) {
            return;
        }

        // Cadência fora ou conta desativada: a regra pausa e o histórico fica.
        // Pausar — e não apagar — é o que mantém as ocorrências já
        // materializadas legíveis depois que a casa cancela o serviço.
        if (! $bill->isActive() || ($cadence && $request->input('due_day') === null)) {
            $this->triggers->pauseForSubject($tenantId, TriggerConfig::SUBJECT_BILL, $bill->id);

            return;
        }

        // Reativar devolve a cadência que a conta já tinha, dia e antecedência
        // inclusive, em vez de exigir que o morador digite tudo de novo.
        if (! $cadence) {
            $this->triggers->resumeForSubject($tenantId, TriggerConfig::SUBJECT_BILL, $bill->id);

            return;
        }

        $attributes = [
            'title' => (string) $bill->name,
            'type' => TriggerConfig::TYPE_CALENDAR_MONTHLY,
            'day_of_month' => $request->integer('due_day'),
            'is_active' => true,
        ];

        // Ausente no payload, a antecedência que já valia continua valendo.
        if ($request->has('advance_notice_days')) {
            $attributes['advance_notice_days'] = $request->integer('advance_notice_days');
        }

        $this->triggers->storeExclusiveForSubject($tenantId, TriggerConfig::SUBJECT_BILL, $bill->id, $attributes);
    }

    /**
     * Desativar a conta não apaga histórico: o que ainda nem venceu deixa de
     * ser dívida, e o que já venceu continua constando até ser pago.
     */
    private function dropUpcomingWhenDeactivated(Request $request, Bill $bill, string $tenantId): void
    {
        if (! $request->has('is_active') || $request->boolean('is_active') || $bill->isActive()) {
            return;
        }

        $today = $this->calendar->day(now(), $tenantId)->format('Y-m-d');

        $this->occurrences->cancelUpcoming($bill->id, $today);
    }

    /**
     * Campos que o pedido traz.
     *
     * Só o que veio no corpo entra: no patch, campo omitido é campo que não
     * muda, e escrever vazio nele apagaria o que já estava gravado. Já
     * `amount` e `category` limpos de propósito chegam como `null`, e aí a
     * limpeza é o que se quer.
     *
     * `amount` é normalizado para duas casas antes de gravar: o que entra no
     * banco é o texto canônico, e não o que o cliente digitou.
     *
     * @return array<string, mixed>
     */
    private function attributes(Request $request): array
    {
        $attributes = $request->only(['name', 'kind', 'category', 'is_active']);

        if ($request->has('currency')) {
            $attributes['currency'] = $this->currency($request);
        }

        if ($request->has('amount')) {
            $attributes['amount'] = $this->amount($request);
        }

        return $attributes;
    }

    /** A casa paga em real; a chave ISO entra em maiúscula para não duplicar. */
    private function currency(Request $request): string
    {
        return strtoupper($request->string('currency')->toString() ?: 'BRL');
    }

    private function amount(Request $request): ?string
    {
        $value = $request->input('amount');

        if ($value === null || $value === '') {
            return null;
        }

        return $this->money->fromCents($this->money->cents($request->string('amount')->toString()));
    }

    /**
     * Relê a conta com a cadência recém-escrita: a regra vive em outra tabela
     * e a resposta precisa mostrá-la sem uma segunda chamada do cliente.
     */
    private function reload(Bill $bill, string $tenantId): Bill
    {
        return $this->bills->find($bill->id, $tenantId) ?? $bill;
    }

    private function find(Request $request, string $billId): Bill
    {
        return $this->bills->findOrFail($billId, $this->tenantId($request));
    }

    private function tenantId(Request $request): string
    {
        // O middleware `tenant` já devolveu 403 sem `tid`; o guard serve para
        // o service nunca receber um id vazio e vazar escopo.
        return ActiveTenant::id($request) ?? throw TenantMismatch::make();
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
