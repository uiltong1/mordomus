<?php

declare(strict_types=1);

namespace Mordomus\Notification\Contracts\Services;

use Illuminate\Http\Request;

interface DeviceTokenServiceInterface
{
    /** @return array<string, mixed> */
    public function index(Request $request): array;

    /** @return array<string, mixed> */
    public function store(Request $request): array;

    /** @return array<string, mixed> */
    public function destroy(Request $request): array;

    /**
     * Envia um aviso de teste para as assinaturas do chamador.
     *
     * @return array<string, mixed>
     */
    public function test(Request $request): array;
}
