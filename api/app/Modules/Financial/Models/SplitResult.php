<?php

declare(strict_types=1);

namespace Mordomus\Financial\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mordomus\Common\Eloquent\BelongsToTenant;
use Mordomus\Identity\Models\User;

#[Fillable([
    'tenant_id',
    'bill_occurrence_id',
    'user_id',
    'share_amount',
    'settled',
    'settled_at',
])]
class SplitResult extends Model
{
    use BelongsToTenant, HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'share_amount' => 'decimal:2',
            'settled' => 'boolean',
            'settled_at' => 'datetime',
        ];
    }

    public function billOccurrence(): BelongsTo
    {
        return $this->belongsTo(BillOccurrence::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSettled(): bool
    {
        return $this->settled;
    }
}
