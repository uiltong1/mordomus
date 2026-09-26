<?php

namespace Mordomus\Identity\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Mordomus\Common\Eloquent\BelongsToTenant;

#[Fillable(['tenant_id', 'quiet_hours', 'channels'])]
class TenantPreference extends Model
{
    use BelongsToTenant, HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** Quiet hours e canais padrão (T1.2.7) */
    public const DEFAULT_QUIET_HOURS = ['start' => '22:00', 'end' => '07:00'];

    public const DEFAULT_CHANNELS = ['email' => true, 'push' => true, 'in_app' => true];

    protected function casts(): array
    {
        return [
            'quiet_hours' => 'array',
            'channels' => 'array',
        ];
    }
}
