<?php

namespace Mordomus\Common\Console;

use Illuminate\Support\Facades\DB;

/**
 * As residências que um varrimento percorre.
 *
 * Leitura da tabela do Identity: a fronteira entre módulos permite ler de
 * outro dono (TECHSPEC §5), e a escrita continua sendo do módulo dono.
 * Arquivada não entra — casa arquivada não gera aviso, vencimento nem
 * projeção de atraso.
 *
 * A consulta vai pelo query builder e não pelo model porque o pacote não
 * conhece o módulo Identity: ele sabe que existe uma tabela de residências,
 * não a classe que a representa no monólito.
 */
trait EnumeratesTenants
{
    /** @return list<string> */
    private function tenantIds(): array
    {
        return array_map(
            strval(...),
            DB::table('tenants')
                ->whereNull('archived_at')
                ->orderBy('id')
                ->pluck('id')
                ->all(),
        );
    }
}