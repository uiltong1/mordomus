<?php

namespace Mordomus\Scheduling\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mordomus\Common\Eloquent\BelongsToTenant;

/**
 * Ocorrência materializada de uma regra: o dia do calendário que pertence ao
 * ciclo e o instante em que ela dispara.
 *
 * `scheduled_for` e `due_at` são colunas separadas porque respondem a
 * perguntas diferentes — a agenda agrupa pelo dia, o varrimento do scheduler
 * pergunta pelo instante.
 *
 * Não há soft delete: dispensar uma ocorrência é `status = skipped`, que
 * preserva a linha como histórico do ciclo.
 */
#[Fillable([
    'tenant_id',
    'trigger_config_id',
    'scheduled_for',
    'due_at',
    'status',
    'notified_at',
    'completed_at',
    'completed_by',
])]
class JobSchedule extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_NOTIFIED = 'notified';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_OVERDUE = 'overdue';

    /**
     * Estados que ainda aceitam check-in ou dispensa do morador.
     *
     * `overdue` entra aqui porque atraso é constatação, não veredito: uma
     * tarefa entregue com atraso continua sendo entrega, e a linha precisa
     * voltar a `completed` para o ciclo advance.
     *
     * @var list<string>
     */
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_NOTIFIED, self::STATUS_OVERDUE];

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_NOTIFIED,
        self::STATUS_COMPLETED,
        self::STATUS_SKIPPED,
        self::STATUS_OVERDUE,
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'date',
            'due_at' => 'datetime',
            'notified_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<JobSchedule, TriggerConfig, $this> */
    public function triggerConfig(): BelongsTo
    {
        return $this->belongsTo(TriggerConfig::class, 'trigger_config_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_SKIPPED], true);
    }
}
