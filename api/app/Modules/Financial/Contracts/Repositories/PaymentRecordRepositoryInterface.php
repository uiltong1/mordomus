<?php

declare(strict_types=1);

namespace Mordomus\Financial\Contracts\Repositories;

use Illuminate\Support\Collection;
use Mordomus\Financial\Models\PaymentRecord;

interface PaymentRecordRepositoryInterface
{
    public function create(array $attributes): PaymentRecord;

    public function forOccurrence(string $billOccurrenceId): Collection;
}
