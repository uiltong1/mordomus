<?php

declare(strict_types=1);

namespace Mordomus\Financial\Contracts\Services;

use Mordomus\Financial\Models\SplitEntry;
use Mordomus\Financial\Models\SplitRule;

/**
 * A matemática da divisão de uma conta entre os moradores.
 *
 * Recebe a regra, suas entradas e o valor do lançamento, e devolve as cotas em
 * centavos fechadas: a soma é igual ao total por construção, sem sobra nem
 * falta (regra R5). É a única parte do split que não depende de banco nem de
 * fuso, por isso mora atrás de uma interface própria e é testada sem HTTP.
 */
interface SplitCalculatorInterface
{
    /**
     * Cota de cada morador, na ordem em que as entradas foram recebidas.
     *
     * A ordem entra no contrato porque o resíduo de arredondamento é absorvido
     * pela última entrada (é o que a R5 descreve), e a última só é a última se
     * a sequência for a mesma. Quem monta a sequência é o repositório, que a
     * entrega na ordem em que a casa cadastrou.
     *
     * @param  list<SplitEntry>  $entries
     * @return list<array{user_id: string, amount: string}> texto decimal com duas casas
     */
    public function shares(SplitRule $rule, array $entries, string $total): array;
}
