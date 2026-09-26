<?php

namespace Mordomus\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Mordomus\Http\Controllers\Controller;
use Mordomus\Identity\Contracts\Services\AuthServiceInterface;
use Mordomus\Identity\Http\Requests\LoginRequest;
use Mordomus\Identity\Http\Requests\LogoutRequest;
use Mordomus\Identity\Http\Requests\RefreshRequest;
use Mordomus\Identity\Http\Requests\RegisterRequest;
use Mordomus\Identity\Http\Requests\SwitchTenantRequest;
use Mordomus\OpenApi\Schemas\Error;
use Mordomus\OpenApi\Schemas\Session;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    public function __construct(private readonly AuthServiceInterface $service) {}

    #[OA\Post(
        path: '/api/v1/identity/auth/register',
        summary: 'Registra usuário e cria a primeira residência',
        tags: ['identity'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'name', type: 'string', maxLength: 120, example: 'Ana Souza'),
            new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 255, example: 'ana@mordomus.test'),
            new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8, maxLength: 72, example: 'senha-forte-123'),
            new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', example: 'senha-forte-123'),
            new OA\Property(property: 'home_name', type: 'string', maxLength: 120, example: 'Casa Principal'),
            new OA\Property(property: 'timezone', type: 'string', maxLength: 64, example: 'America/Sao_Paulo'),
        ], required: ['name', 'email', 'password', 'password_confirmation'])),
        responses: [
            new OA\Response(response: 201, description: 'Usuário e residência criados', content: new OA\JsonContent(ref: Session::class)),
            new OA\Response(response: 422, description: 'Dados inválidos', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway ou do endpoint', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function register(RegisterRequest $request): JsonResponse
    {
        return response()->json($this->service->register($request), 201);
    }

    #[OA\Post(
        path: '/api/v1/identity/auth/login',
        summary: 'Autentica e emite access + refresh token',
        tags: ['identity'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'email', type: 'string', format: 'email', example: 'ana@mordomus.test'),
            new OA\Property(property: 'password', type: 'string', format: 'password', example: 'senha-forte-123'),
            new OA\Property(property: 'tenant_id', type: 'string', description: 'Residência desejada; sem ela usa a mais antiga ativa'),
        ], required: ['email', 'password'])),
        responses: [
            new OA\Response(response: 200, description: 'Sessão emitida', content: new OA\JsonContent(ref: Session::class)),
            new OA\Response(response: 401, description: 'E-mail ou senha inválidos', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Sem membership na residência informada', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway ou do endpoint', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        return response()->json($this->service->login($request));
    }

    #[OA\Post(
        path: '/api/v1/identity/auth/refresh',
        summary: 'Rotaciona o refresh token e emite nova sessão',
        tags: ['identity'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'refresh_token', type: 'string', minLength: 32, maxLength: 128),
        ], required: ['refresh_token'])),
        responses: [
            new OA\Response(response: 200, description: 'Sessão renovada; o refresh enviado deixa de valer', content: new OA\JsonContent(ref: Session::class)),
            new OA\Response(response: 401, description: 'Refresh token inválido, expirado ou reutilizado', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway ou do endpoint', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function refresh(RefreshRequest $request): JsonResponse
    {
        return response()->json($this->service->refresh($request));
    }

    #[OA\Post(
        path: '/api/v1/identity/auth/logout',
        summary: 'Revoga o refresh token enviado',
        tags: ['identity'],
        security: [['jwtBearerAuth' => []]],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'refresh_token', type: 'string', minLength: 32, maxLength: 128),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Refresh token revogado (idempotente)', content: new OA\JsonContent(type: 'object', properties: [
                new OA\Property(property: 'status', type: 'string', example: 'ok'),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function logout(LogoutRequest $request): JsonResponse
    {
        return response()->json($this->service->logout($request));
    }

    #[OA\Post(
        path: '/api/v1/identity/auth/switch-tenant',
        summary: 'Emite sessão com outra residência ativa',
        tags: ['identity'],
        security: [['jwtBearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'tenant_id', type: 'string', example: '01J8Z0M9W3K6Q2T4R5Y7B8C9D0'),
        ], required: ['tenant_id'])),
        responses: [
            new OA\Response(response: 200, description: 'Novo access token com o claim tid atualizado', content: new OA\JsonContent(ref: Session::class)),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Sem membership na residência informada', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function switchTenant(SwitchTenantRequest $request): JsonResponse
    {
        return response()->json($this->service->switchTenant($request));
    }
}
