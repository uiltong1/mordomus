<?php

namespace Mordomus\Identity\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['tenant_id', 'key', 'name', 'is_system'])]
class Role extends Model
{
    use HasUlids;

    public const OWNER = 'owner';

    public const MEMBER = 'member';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions', 'role_id', 'permission_id')
            ->withPivot('granted');
    }

    /** Role de sistema (tenant_id nulo) pela key. */
    public static function systemByKey(string $key): self
    {
        return static::query()->whereNull('tenant_id')->where('key', $key)->firstOrFail();
    }
}
