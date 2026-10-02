<?php

namespace Mordomus\Identity\Events;

/**
 * Nomes dos eventos que o Identity publica.
 *
 * Uma classe só porque o nome é o contrato: é o que o Notification conhece e
 * o que o JSON Schema de `packages/contracts` declara.
 */
final class EventName
{
    /**
     * Morador entrou na residência, com o papel que recebeu.
     *
     * Sai para quem entrou, e não para os outros: a casa já vê o morador na
     * tela de membros, e o único que não sabe que está dentro é o novo.
     */
    public const MEMBER_ADDED = 'tenant.member_added';
}
