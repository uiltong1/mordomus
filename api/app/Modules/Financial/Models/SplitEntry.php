<?php

declare(strict_types=1);

namespace Mordomus\Financial\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mordomus\Identity\Models\User;

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

            'weight' => 'decimal:2',
            'percent' => 'decimal:2',
            'fixed_amount' => 'decimal:2',
        ];
    }

    public function splitRule(): BelongsTo
    {
        return $this->belongsTo(SplitRule::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
