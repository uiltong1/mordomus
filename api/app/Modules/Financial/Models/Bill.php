<?php

namespace Mordomus\Financial\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mordomus\Common\Eloquent\BelongsToTenant;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * Conta da residência: o que a casa paga.
 *
 * `fixed` tem valor e dia de vencimento conhecidos; `variable` tem valor que
 * só existe quando a conta chega (luz, água). A cadência não mora aqui: ela é
 * a regra de recorrência do Scheduling apontada por `trigger_configs.bill_id`
 * (ADR-011), e esta tabela só a lê.
 */
#[Fillable([
    'tenant_id',
    'name',
    'kind',
    'category',
    'amount',
    'currency',
    'is_active',
    'created_by',
])]
class Bill extends Model
{
    use BelongsToTenant, HasUlids;

    /** Valor e vencimento conhecidos: a cadência mensal resolve o dia. */
    public const KIND_FIXED = 'fixed';

    /** Valor que só se sabe na conta: o morador registra o lançamento. */
    public const KIND_VARIABLE = 'variable';

    /** @var list<string> */
    public const KINDS = [self::KIND_FIXED, self::KIND_VARIABLE];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'kind' => 'string',
            // `decimal:2` e não `float`: centavo é dinheiro, e binário de
            // ponto flutuante não fecha soma de conta — o que importa aqui é
            // que o valor gravado e o valor devolvido sejam o mesmo texto.
            'amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<BillOccurrence, $this> */
    public function occurrences(): HasMany
    {
        return $this->hasMany(BillOccurrence::class);
    }

    /**
     * Regras de recorrência que apontam para esta conta.
     *
     * Leitura de outro módulo (o dono é o Scheduling), usada só para
     * mostrar a cadência da conta na resposta da API.
     *
     * @return HasMany<TriggerConfig, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(TriggerConfig::class, 'bill_id');
    }

    /** @return HasMany<SplitRule, $this> */
    public function splitRules(): HasMany
    {
        return $this->hasMany(SplitRule::class);
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function isFixed(): bool
    {
        return $this->kind === self::KIND_FIXED;
    }
}
