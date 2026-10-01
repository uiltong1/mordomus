<?php

declare(strict_types=1);

namespace Mordomus\Financial\Repositories;

use Illuminate\Support\Collection;
use Mordomus\Financial\Contracts\Repositories\PaymentRecordRepositoryInterface;
use Mordomus\Financial\Models\PaymentRecord;

final class PaymentRecordRepository implements PaymentRecordRepositoryInterface
{
    public function create(array $attributes): PaymentRecord
    {
        return PaymentRecord::create($attributes);
    }

    public function forOccurrence(string $billOccurrenceId): Collection
    {
        return PaymentRecord::query()
            ->where('bill_occurrence_id', $billOccurrenceId)
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get();
    }
}
