<?php

namespace Mordomus\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Mordomus\Http\Controllers\Controller;
use Mordomus\Identity\Contracts\Services\ProfileServiceInterface;
use Mordomus\Identity\Http\Requests\ProfileRequest;
use Mordomus\OpenApi\Schemas\Error;
use Mordomus\OpenApi\Schemas\TenantSummary;
use Mordomus\OpenApi\Schemas\User;
use OpenApi\Attributes as OA;

class MeController extends Controller
{
    public function __construct(private readonly ProfileServiceInterface $service) {}

    #[OA\Get(
        path: '/api/v1/identity/me',
        summary: 'Perfil, residências e capabilities efetivas do token',
        tags: ['identity'],
        security: [['jwtBearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Perfil do usuário autenticado', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'object', required: ['user', 'active_tenant', 'tenants', 'capabilities'], properties: [
                    new OA\Property(property: 'user', ref: User::class),
                    new OA\Property(property: 'active_tenant', type: 'string', nullable: true),
                    new OA\Property(property: 'tenants', type: 'array', items: new OA\Items(ref: TenantSummary::class)),
                    new OA\Property(property: 'capabilities', type: 'array', items: new OA\Items(type: 'string')),
                ]),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Sem residência ativa no token', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function show(ProfileRequest $request): JsonResponse
    {
        return response()->json($this->service->show($request));
    }
}
