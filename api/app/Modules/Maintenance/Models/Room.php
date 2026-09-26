<?php

namespace Mordomus\Maintenance\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Mordomus\Common\Eloquent\BelongsToTenant;

/**
 * T2.1 — ambiente do imóvel.
 *
 * `BelongsToTenant` dá o auto-fill de `tenant_id` e a leitura filtrada
 * (regra R1 / T1.3.4); o escopo não deixa passar linha de outra residência.
 */
#[Fillable(['tenant_id', 'name', 'icon', 'sort_order', 'archived_at'])]
class Room extends Model
{
    use BelongsToTenant, HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'archived_at' => 'datetime',
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
}
