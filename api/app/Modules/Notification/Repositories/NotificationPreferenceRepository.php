<?php

declare(strict_types=1);

namespace Mordomus\Notification\Repositories;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Mordomus\Notification\Contracts\Repositories\NotificationPreferenceRepositoryInterface;
use Mordomus\Notification\Models\NotificationPreference;

final class NotificationPreferenceRepository implements NotificationPreferenceRepositoryInterface
{
    public function findForUser(string $tenantId, string $userId): ?NotificationPreference
    {
        return NotificationPreference::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->first();
    }

    public function findForHouse(string $tenantId): ?NotificationPreference
    {
        return NotificationPreference::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('user_id')
            ->first();
    }

    /**
     * Grava a preferência do morador, criando a linha se ainda não existir.
     *
     * A corrida entre dois PUT do mesmo morador é resolvida pelo índice único
     * em vez de por uma checagem antes: quem perder o `INSERT` atualiza a linha
     * que ganhou, e o resultado é o mesmo que o do `upsert` do SQL.
     */
    public function upsertForUser(string $tenantId, string $userId, array $attributes): NotificationPreference
    {
        $existing = $this->findForUser($tenantId, $userId);

        if ($existing !== null) {
            $existing->fill($attributes);
            $existing->save();

            return $existing;
        }

        try {
            return NotificationPreference::create(['tenant_id' => $tenantId, 'user_id' => $userId] + $attributes);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $winner = $this->findForUser($tenantId, $userId);
            $winner?->fill($attributes);
            $winner?->save();

            return $winner ?? throw $exception;
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return DB::connection($exception->getConnectionName())->isUniqueConstraintError($exception);
    }
}
