<?php

namespace Mordomus\Identity\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'timezone', 'preferred_hour'])]
class Tenant extends Model
{
    use HasFactory, HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'preferred_hour' => 'string',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant): void {
            if (! $tenant->slug) {
                $tenant->slug = static::uniqueSlug($tenant->name);
            }
            if (! $tenant->preferred_hour) {
                $tenant->preferred_hour = '09:00';
            }
        });
    }

    /** Residências ainda não arquivadas (soft delete via archived_at). */
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
        $this->memberships()->update(['status' => Membership::STATUS_ARCHIVED]);
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return HasMany<Invitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /** @return HasOne<TenantPreference, $this> */
    public function preferences(): HasOne
    {
        return $this->hasOne(TenantPreference::class);
    }

    /** `preferred_hour` armazenado como `HH:MM:SS` — exposto como `HH:MM`. */
    public function getPreferredHourAttribute(?string $value): ?string
    {
        return $value === null ? null : substr($value, 0, 5);
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'residencia';
        $slug = $base;
        $suffix = 1;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
