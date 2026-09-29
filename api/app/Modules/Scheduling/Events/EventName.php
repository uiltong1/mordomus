<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Events;

/**
 * Nomes dos eventos que o Scheduling publica.
 *
 * Ficam numa classe só porque o nome é o contrato: é o que o consumidor
 * conhece e o que o JSON Schema de `packages/contracts` declara.
 */
final class EventName
{
    /** Vencimento (ou aviso antecipado / offset escalonado) de uma ocorrência. */
    public const SCHEDULE_DUE = 'schedule.due';

    /** Check-in do morador; carrega a próxima data já recalculada. */
    public const OCCURRENCE_COMPLETED = 'occurrence.completed';

    /** Ocorrência materializada pelo motor — o Financial persiste `schedule_id` a partir daqui. */
    public const OCCURRENCE_CREATED = 'schedule.occurrence.created';
}
