<?php

declare(strict_types=1);

namespace Mordomus\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Mordomus\OpenApi\Schemas\ServiceInfo;
use OpenApi\Attributes as OA;

final class RootController
{
    #[OA\Get(
        path: '/',
        operationId: 'rootInfo',
        summary: 'Identificação do serviço na raiz do host',
        tags: ['system'],
        responses: [
            new OA\Response(response: 200, description: 'Serviço no ar', content: new OA\JsonContent(ref: ServiceInfo::class)),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'service' => config('app.name'),
            'status' => 'ok',
        ]);
    }
}
