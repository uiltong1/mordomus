<?php

declare(strict_types=1);

namespace Mordomus\Notification\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mordomus\Http\Exceptions\TenantMismatch;
use Mordomus\Http\Tenancy\ActiveTenant;
use Mordomus\Notification\Contracts\Repositories\DeviceTokenRepositoryInterface;
use Mordomus\Notification\Contracts\Services\DeviceTokenServiceInterface;
use Mordomus\Notification\Contracts\Services\PushChannelInterface;
use Mordomus\Notification\Contracts\Services\VapidTokenServiceInterface;
use Mordomus\Notification\Exceptions\DeviceTokenNotFound;
use Mordomus\Notification\Exceptions\PushNotConfigured;
use Mordomus\Notification\Http\Resources\DeviceTokenResource;
use Throwable;

/**
 * Assinaturas de Web Push do morador (ADR-001).
 *
 * Registrar de novo a mesma assinatura **atualiza** a linha em vez de criar
 * outra: o clique no sino pode acontecer quantas vezes o morador quiser, e
 * duplicar a assinatura faria o mesmo aviso sair duas vezes para o mesmo
 * navegador. O `last_seen_at` é o que a atualização grava, e é ele que a
 * limpeza usa para decidir o que já está parado faz tempo.
 *
 * O envio de teste é síncrono e responde o que o serviço de push respondeu.
 * Ele não passa pela fila de propósito: a tela precisa saber agora se a
 * assinatura funciona, e um teste que chega em 30 segundos não responde a
 * pergunta que o morador fez ("isto está funcionando?").
 */
final class DeviceTokenService implements DeviceTokenServiceInterface
{
    public function __construct(
        private readonly DeviceTokenRepositoryInterface $devices,
        private readonly PushChannelInterface $push,
        private readonly VapidTokenServiceInterface $vapid,
        private readonly DeviceTokenResource $resource,
    ) {}

    public function index(Request $request): array
    {
        $tenantId = $this->tenantId($request);

        return [
            'data' => $this->resource->collection(
                $this->devices->forUser($tenantId, (string) $request->user()->id),
            ),
        ];
    }

    public function store(Request $request): array
    {
        $tenantId = $this->tenantId($request);
        $userId = (string) $request->user()->id;
        $endpoint = $request->string('endpoint')->toString();

        $attributes = [
            'platform' => $request->string('platform')->toString(),
            'p256dh' => $request->string('p256dh')->toString(),
            'auth' => $request->string('auth')->toString(),
            'last_seen_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];

        $device = $this->devices->findByEndpoint($tenantId, $userId, $endpoint);

        $device = $device === null
            ? $this->devices->create(['tenant_id' => $tenantId, 'user_id' => $userId, 'endpoint' => $endpoint] + $attributes)
            : $this->devices->change($device, $attributes);

        return ['data' => $this->resource->make($device)];
    }

    public function destroy(Request $request): array
    {
        $tenantId = $this->tenantId($request);
        $endpoint = $request->string('endpoint')->toString();

        $removed = $this->devices->forget($tenantId, (string) $request->user()->id, $endpoint);

        if ($removed === 0) {
            throw DeviceTokenNotFound::make();
        }

        return ['data' => ['removed' => $removed, 'endpoint' => $endpoint]];
    }

    public function test(Request $request): array
    {
        $tenantId = $this->tenantId($request);
        $userId = (string) $request->user()->id;

        if (! $this->vapid->isConfigured()) {
            throw PushNotConfigured::make();
        }

        $devices = $this->devices->forUser($tenantId, $userId);
        $content = [
            'title' => 'Mordomus',
            'lines' => ['Se você está vendo isto, as notificações deste navegador funcionam.'],
            'tag' => 'mordomus:teste',
            'url' => '/',
            'data' => ['test' => true],
        ];

        $delivered = 0;
        $gone = 0;
        $errors = [];

        foreach ($devices as $device) {
            try {
                if ($this->push->send($device, $content)) {
                    $delivered++;

                    continue;
                }

                $gone++;
                $this->devices->forget($tenantId, $userId, (string) $device->endpoint);
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        Log::info('notification.device_test', [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'devices' => $devices->count(),
            'delivered' => $delivered,
            'gone' => $gone,
            'errors' => $errors,
        ]);

        return ['data' => [
            'devices' => $devices->count(),
            'delivered' => $delivered,
            'gone' => $gone,
            'errors' => $errors,
        ]];
    }

    private function tenantId(Request $request): string
    {
        // O middleware `tenant` já devolveu 403 sem `tid`; o guard serve para
        // o service nunca receber um id vazio e vazar escopo.
        return ActiveTenant::id($request) ?? throw TenantMismatch::make();
    }
}
