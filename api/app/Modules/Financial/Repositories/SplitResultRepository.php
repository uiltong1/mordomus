<?php

declare(strict_types=1);

namespace Mordomus\Financial\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Mordomus\Financial\Contracts\Repositories\SplitResultRepositoryInterface;
use Mordomus\Financial\Models\SplitResult;

final class SplitResultRepository implements SplitResultRepositoryInterface
{
    public function forOccurrence(string $tenantId, string $billOccurrenceId): Collection
    {
        return $this->query()
            ->where('tenant_id', $tenantId)
            ->where('bill_occurrence_id', $billOccurrenceId)
            ->orderBy('id')
            ->get();
    }

    public function find(string $tenantId, string $billOccurrenceId, string $userId): ?SplitResult
    {
        return $this->query()
            ->where('tenant_id', $tenantId)
            ->where('bill_occurrence_id', $billOccurrenceId)
            ->where('user_id', $userId)
            ->first();
    }

    public function sync(string $tenantId, string $billOccurrenceId, array $shares): Collection
    {
        $previous = $this->forOccurrence($tenantId, $billOccurrenceId)->keyBy('user_id');
        $saved = [];

        foreach ($shares as $share) {
            $existing = $previous->get($share['user_id']);
            $changed = $existing === null || $existing->share_amount !== $share['amount'];

            $result = $existing ?? new SplitResult([
                'tenant_id' => $tenantId,
                'bill_occurrence_id' => $billOccurrenceId,
            ]);

            $result->fill([
                'user_id' => $share['user_id'],
                'share_amount' => $share['amount'],

                'settled' => $changed ? false : $result->settled,
                'settled_at' => $changed ? null : $result->settled_at,
            ]);

            $result->save();

            $saved[$share['user_id']] = $result;
        }

        foreach ($previous as $userId => $result) {
            if (! array_key_exists($userId, $saved)) {
                $result->delete();
            }
        }

        return collect($shares)
            ->map(fn (array $share): SplitResult => $saved[$share['user_id']])
            ->values();
    }

    public function forget(string $tenantId, string $billOccurrenceId): int
    {
        return SplitResult::query()
            ->where('tenant_id', $tenantId)
            ->where('bill_occurrence_id', $billOccurrenceId)
            ->delete();
    }

    public function settle(SplitResult $result, bool $settled, ?string $settledAt): SplitResult
    {

        if ($result->isSettled() === $settled) {
            return $result;
        }

        $result->fill([
            'settled' => $settled,
            'settled_at' => $settled ? $settledAt : null,
        ]);

        $result->save();

        return $result;
    }

    private function query(): Builder
    {
        return SplitResult::query();
    }
}
