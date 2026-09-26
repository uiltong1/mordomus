<?php

namespace Mordomus\Maintenance\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mordomus\Common\Eloquent\BelongsToTenant;

/**
 * T2.2 — item do inventário (Ar-condicionado, Sofá, Filtro…), sempre dentro
 * de um `room` da mesma residência (regra R1 + AC da T2.2).
 */
#[Fillable([
    'tenant_id',
    'room_id',
    'name',
    'category',
    'brand',
    'model',
    'acquired_at',
    'warranty_until',
    'metadata',
    'archived_at',
])]
class Asset extends Model
{
    use BelongsToTenant, HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'acquired_at' => 'datetime',
            'warranty_until' => 'datetime',
            'archived_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** Só os não arquivados (soft delete — ADR-006). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function archive(): void
    {
        $this->forceFill(['archived_at' => now()])->saveQuietly();
    }

    public function restore(): void
    {
        $this->forceFill(['archived_at' => null])->saveQuietly();
    }

    /** Cômodo que hospeda o ativo — mesma residência garantida pelo escopo global. */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
