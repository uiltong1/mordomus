<?php

namespace Mordomus\Financial\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mordomus\Common\Eloquent\BelongsToTenant;
use Mordomus\Scheduling\Models\TriggerConfig;

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

    public const KIND_FIXED = 'fixed';

    public const KIND_VARIABLE = 'variable';

    public const KINDS = [self::KIND_FIXED, self::KIND_VARIABLE];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'kind' => 'string',

            'amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(BillOccurrence::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(TriggerConfig::class, 'bill_id');
    }

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
