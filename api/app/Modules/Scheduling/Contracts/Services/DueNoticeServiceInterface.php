<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Contracts\Services;

use Carbon\CarbonImmutable;

/**
 * Varrimento de avisos: o que precisa chegar ao morador agora.
 */
interface DueNoticeServiceInterface
{
    /** Quantos avisos foram publicados para a residência. */
    public function publish(string $tenantId, CarbonImmutable $now): int;
}
