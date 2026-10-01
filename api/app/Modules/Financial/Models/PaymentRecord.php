<?php

namespace Mordomus\Financial\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mordomus\Common\Eloquent\BelongsToTenant;

/**
 * Baixa de pagamento: o que foi pago, por quem, como e quando.
 *
 * Append-only — sem `created_at`/`updated_at` porque `paid_at` é o único
 * relógio da linha. `amount` é o que foi pago e não o previsto no vencimento:
 * em conta variável é o único lugar onde o valor real aparece.
 */
#[Fillable([
    'tenant_id',
    'bill_occurrence_id',
    'user_id',
    'amount',
    'method',
    'paid_at',
    'receipt_url',
])]
class PaymentRecord extends Model
{
    use BelongsToTenant, HasUlids;

    public const METHOD_PIX = 'pix';

    public const METHOD_BOLETO = 'boleto';

    public const METHOD_DEBIT_CARD = 'debit_card';

    public const METHOD_CREDIT_CARD = 'credit_card';

    public const METHOD_CASH = 'cash';

    public const METHOD_TRANSFER = 'transfer';

    public const METHOD_OTHER = 'other';

    /** @var list<string> */
    public const METHODS = [
        self::METHOD_PIX,
        self::METHOD_BOLETO,
        self::METHOD_DEBIT_CARD,
        self::METHOD_CREDIT_CARD,
        self::METHOD_CASH,
        self::METHOD_TRANSFER,
        self::METHOD_OTHER,
    ];

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BillOccurrence, $this> */
    public function billOccurrence(): BelongsTo
    {
        return $this->belongsTo(BillOccurrence::class);
    }
}
