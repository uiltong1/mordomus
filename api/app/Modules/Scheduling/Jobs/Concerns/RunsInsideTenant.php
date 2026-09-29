<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Jobs\Concerns;

use Mordomus\Common\Support\TenantContext;

/**
 * Job que roda com o contexto de tenant da residência que ele carrega.
 *
 * O contexto vem do JWT na requisição HTTP; na fila não existe requisição, e
 * o escopo global do Eloquent é fail-closed fora dele — sem esta moldura o
 * job leria e escreveria zero linhas de todo tenant, em silêncio.
 */
trait RunsInsideTenant
{
    /**
     * @template TRet
     *
     * @param  callable(): TRet  $callback
     * @return TRet
     */
    private function insideTenant(callable $callback): mixed
    {
        return TenantContext::runWith($this->tenantId, $callback);
    }
}
