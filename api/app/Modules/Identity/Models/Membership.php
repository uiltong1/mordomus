<?php

namespace Mordomus\Identity\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Mordomus\Common\Eloquent\BelongsToTenant;

#[Fillable(['user_id', 'tenant_id', 'role_id', 'status'])]
class Membership extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INVITED = 'invited';

    public const STATUS_ARCHIVED = 'archived';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'status' => 'string',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** Overrides pontuais. @return BelongsToMany<Permission, $this> */
    public function permissionGrants(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'membership_grants', 'membership_id', 'permission_id')
            ->withPivot('granted');
    }
}
