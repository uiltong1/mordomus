<?php

declare(strict_types=1);

namespace Mordomus\Financial\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mordomus\Identity\Models\User;

/**
 * Morador de uma regra de divisão, com o peso dele no cálculo.
 *
 * Carrega o campo do regime em uso — `weight`, `percent` ou `fixed_amount` — e
 * os outros dois ficam nulos. Quem sabe qual vale é o `mode` da regra, e é o
 * `SplitCalculator` que faz essa leitura; a linha em si não guarda o regime,
 * para que a mesma entrada não precise ser reescrita quando a regra muda de
 * `WEIGHTED` para `PERCENT` e de volta.
 */
#[Fillable([
    'split_rule_id',
    'user_id',
    'weight',
    'percent',
    'fixed_amount',
])]
class SplitEntry extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            // `decimal:2` e não `float`: o centavo do peso também é dinheiro
            // depois de dividido pelo total da conta.
            'weight' => 'decimal:2',
            'percent' => 'decimal:2',
            'fixed_amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<SplitRule, $this> */
    public function splitRule(): BelongsTo
    {
        return $this->belongsTo(SplitRule::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
