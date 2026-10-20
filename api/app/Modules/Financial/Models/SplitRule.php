<?php

declare(strict_types=1);

namespace Mordomus\Financial\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Mordomus\Common\Eloquent\BelongsToTenant;

#[Fillable([
    'tenant_id',
    'bill_id',
    'mode',
    'is_active',
])]
class SplitRule extends Model
{
    use BelongsToTenant, HasUlids;

    public const MODE_EQUAL = 'EQUAL';

    public const MODE_WEIGHTED = 'WEIGHTED';

    public const MODE_PERCENT = 'PERCENT';

    public const MODE_CUSTOM = 'CUSTOM';

    public const MODES = [
        self::MODE_EQUAL,
        self::MODE_WEIGHTED,
        self::MODE_PERCENT,
        self::MODE_CUSTOM,
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'mode' => 'string',
            'is_active' => 'boolean',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(SplitEntry::class);
    }

    public function orderedEntries(): Collection
    {
        $entries = $this->relationLoaded('entries') ? $this->entries : $this->entries()->get();

        return $entries->sortBy('id')->values();
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function isHouseDefault(): bool
    {
        return $this->bill_id === null;
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }
}
