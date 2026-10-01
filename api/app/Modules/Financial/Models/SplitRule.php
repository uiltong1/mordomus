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

/**
 * Regra de divisão: como a casa reparte uma conta entre os moradores.
 *
 * `bill_id` nulo é a regra padrão da casa — vale para toda conta que não tem
 * regra própria. `mode` é o regime do cálculo e decide qual campo da entrada
 * o motor lê (`SplitCalculator`); os outros dois ficam nulos.
 */
#[Fillable([
    'tenant_id',
    'bill_id',
    'mode',
    'is_active',
])]
class SplitRule extends Model
{
    use BelongsToTenant, HasUlids;

    /** Mesma cota para todo morador da regra. */
    public const MODE_EQUAL = 'EQUAL';

    /** Proporcional a um peso por morador (1.00, 2.00, 1.50). */
    public const MODE_WEIGHTED = 'WEIGHTED';

    /** Proporcional a um percentual por morador; a soma fecha em 100. */
    public const MODE_PERCENT = 'PERCENT';

    /** Valor fechado por morador. */
    public const MODE_CUSTOM = 'CUSTOM';

    /** @var list<string> */
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

    /** @return HasMany<SplitEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(SplitEntry::class);
    }

    /**
     * Participantes na ordem em que a casa cadastrou.
     *
     * A ordem decide quem recebe o resíduo de arredondamento da R5, então ela
     * não pode ser a do banco: o ULID cresce com o tempo, e a casa os digita
     * numa ordem que vale para o cálculo. Usa a relação já carregada quando
     * ela existe, para a listagem das regras não abrir uma consulta por linha.
     *
     * @return Collection<int, SplitEntry>
     */
    public function orderedEntries(): Collection
    {
        $entries = $this->relationLoaded('entries') ? $this->entries : $this->entries()->get();

        return $entries->sortBy('id')->values();
    }

    /** @return BelongsTo<Bill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    /** Regra da casa: vale para toda conta sem regra própria. */
    public function isHouseDefault(): bool
    {
        return $this->bill_id === null;
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }
}
