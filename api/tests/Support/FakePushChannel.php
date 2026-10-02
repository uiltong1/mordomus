<?php

declare(strict_types=1);

namespace Tests\Support;

use Mordomus\Notification\Contracts\Services\PushChannelInterface;
use Mordomus\Notification\Models\DeviceToken;

/**
 * Canal de push de mentira, com o comportamento que a fila precisa ver.
 *
 * O provedor é a única fronteira que mente para o monólito: ele devolve 201
 * quando aceitou, 404/410 quando a assinatura morreu e recusa qualquer outra
 * coisa. O duplo reproduz os três caminhos, e cada resposta pode ser trocada
 * por um endpoint para o fan-out de um morador com mais de uma assinatura.
 */
final class FakePushChannel implements PushChannelInterface
{
    /** @var list<array{endpoint: string, content: array<string, mixed>}> */
    public array $sent = [];

    /** @param array<string, bool> $endpoints resposta por endpoint; o que faltar é aceito */
    public function __construct(private array $endpoints = []) {}

    public function send(DeviceToken $device, array $content): bool
    {
        $outcome = $this->endpoints[(string) $device->endpoint] ?? true;

        if ($outcome === 'refuse') {
            throw new \RuntimeException('O serviço de push recusou a mensagem com 500.');
        }

        if ($outcome === true) {
            $this->sent[] = ['endpoint' => (string) $device->endpoint, 'content' => $content];
        }

        return $outcome;
    }
}
