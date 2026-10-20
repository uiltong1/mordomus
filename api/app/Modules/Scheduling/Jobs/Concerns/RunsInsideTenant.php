<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Jobs\Concerns;

use Mordomus\Common\Support\TenantContext;

trait RunsInsideTenant
{
    private function insideTenant(callable $callback): mixed
    {
        return TenantContext::runWith($this->tenantId, $callback);
    }
}
