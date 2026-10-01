<?php

declare(strict_types=1);

namespace Mordomus\Financial\Contracts\Repositories;

use Illuminate\Support\Collection;
use Mordomus\Financial\Models\PaymentRecord;

interface PaymentRecordRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): PaymentRecord;

    /**
     * Baixas de um vencimento, do mais antigo para o mais novo.
     *
     * @return Collection<int, PaymentRecord>
     */
    public function forOccurrence(string $billOccurrenceId): Collection;
}
