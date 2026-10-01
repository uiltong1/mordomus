<?php

namespace Mordomus\Financial\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mordomus\Common\Eloquent\BelongsToTenant;

/**
 * Vencimento de conta: uma linha por dia a pagar.
 *
 * A data vem pronta — do evento `schedule.occurrence.created` quando há
 * cadência, do morador quando o lançamento é manual (regra R7: este módulo não
 * calcula data). `schedule_id` nulo é o lançamento manual, e é a diferença
 * entre um vencimento que se repete e um que o morador registrou uma vez só.
 */
#[Fillable([
    'tenant_id',
    'bill_id',
    'schedule_id',
    'due_date',
    'amount',
    'status',
    'paid_at',
    'paid_by',
])]
class BillOccurrence extends Model
{
    use BelongsToTenant, HasUlids;

    /** A pagar: ainda não venceu e não foi paga. */
    public const STATUS_OPEN = 'open';

    /** Quitada: `paid_at` e `paid_by` dizem quando e quem. */
    public const STATUS_PAID = 'paid';

    /** Venceu sem pagamento. */
    public const STATUS_OVERDUE = 'overdue';

    /** Deixou de valer: conta desativada ou lançamento descartado. */
    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_PAID,
        self::STATUS_OVERDUE,
        self::STATUS_CANCELLED,
    ];

    /**
     * Estados que ainda aceitam pagamento.
     *
     * `overdue` entra aqui porque atraso é constatação, não veredito: conta
     * paga com atraso continua paga, e a linha precisa sair de `overdue` para
     * `paid` do mesmo jeito que sai de `open`.
     *
     * @var list<string>
     */
    public const PAYABLE_STATUSES = [self::STATUS_OPEN, self::STATUS_OVERDUE];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * Dia de calendário do vencimento, como `Y-m-d`.
     *
     * `due_date` não tem cast de data de propósito: o cast transforma o dia em
     * instante (`2026-04-10 00:00:00`) ao gravar, e a coluna passa a ser
     * comparada com texto — o que quebra o filtro de período no fim do mês e
     * a busca por data, e é o que o módulo do dono da agenda também evita ao
     * gravar `scheduled_for`.
     */
    public function dueDate(): string
    {
        return CarbonImmutable::parse($this->due_date)->format('Y-m-d');
    }

    /** @return BelongsTo<Bill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    /** @return HasMany<PaymentRecord, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(PaymentRecord::class);
    }

    /**
     * Cotas já calculadas deste vencimento.
     *
     * Entra na resposta porque é a resposta natural da pergunta que a lista de
     * vencimentos faz — "isso já foi dividido, e quem já quitou a parte dele".
     *
     * @return HasMany<SplitResult, $this>
     */
    public function splitResults(): HasMany
    {
        return $this->hasMany(SplitResult::class);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isPayable(): bool
    {
        return in_array($this->status, self::PAYABLE_STATUSES, true);
    }

    public function isFromSchedule(): bool
    {
        return $this->schedule_id !== null;
    }
}
