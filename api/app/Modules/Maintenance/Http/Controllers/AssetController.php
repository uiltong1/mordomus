<?php

namespace Mordomus\Maintenance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Mordomus\Http\Controllers\Controller;
use Mordomus\Maintenance\Contracts\Services\AssetServiceInterface;
use Mordomus\Maintenance\Http\Requests\DestroyAssetRequest;
use Mordomus\Maintenance\Http\Requests\IndexAssetsRequest;
use Mordomus\Maintenance\Http\Requests\ShowAssetRequest;
use Mordomus\Maintenance\Http\Requests\StoreAssetRequest;
use Mordomus\Maintenance\Http\Requests\UpdateAssetRequest;
use Mordomus\OpenApi\Schemas\Asset as AssetSchema;
use Mordomus\OpenApi\Schemas\Error;
use Mordomus\OpenApi\Schemas\PageMeta;
use OpenApi\Attributes as OA;

/**
 * Inventário do tenant: CRUD, transferência entre cômodos, filtros por
 * cômodo/categoria e paginação offset.
 *
 * Datas entram e saem no fuso da residência; o `room_id` é resolvido sempre
 * dentro do escopo de residência, então um cômodo de outra residência não
 * existe aqui — vira `404 room_not_found`.
 */
class AssetController extends Controller
{
    public function __construct(private readonly AssetServiceInterface $assets) {}

    #[OA\Get(
        path: '/api/v1/maintenance/assets',
        summary: 'Lista o inventário da residência ativa',
        tags: ['maintenance'],
        security: [['jwtBearerAuth' => []]],
        parameters: [
            new OA\Parameter(parameter: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
            new OA\Parameter(parameter: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 25, minimum: 1, maximum: 100)),
            new OA\Parameter(parameter: 'room_id', in: 'query', description: 'Filtra por cômodo', schema: new OA\Schema(type: 'string', maxLength: 26)),
            new OA\Parameter(parameter: 'category', in: 'query', description: 'Filtra por categoria', schema: new OA\Schema(type: 'string', maxLength: 40)),
            new OA\Parameter(parameter: 'include_archived', in: 'query', schema: new OA\Schema(type: 'boolean', default: false)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Ativos paginados com datas no fuso da residência', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: AssetSchema::class)),
                new OA\Property(property: 'meta', ref: PageMeta::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Sem residência ativa no token', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function index(IndexAssetsRequest $request): JsonResponse
    {
        return response()->json($this->assets->index($request));
    }

    #[OA\Get(
        path: '/api/v1/maintenance/assets/{asset}',
        summary: 'Detalhe de um ativo',
        tags: ['maintenance'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\PathParameter(parameter: 'asset', description: 'Id ULID do ativo', required: true, schema: new OA\Schema(type: 'string', maxLength: 26))],
        responses: [
            new OA\Response(response: 200, description: 'Ativo', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: AssetSchema::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Sem residência ativa no token', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Ativo não encontrado na residência ativa', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function show(ShowAssetRequest $request, string $asset): JsonResponse
    {
        return response()->json($this->assets->show($request, $asset));
    }

    #[OA\Post(
        path: '/api/v1/maintenance/assets',
        summary: 'Cria um ativo em um cômodo ativo',
        tags: ['maintenance'],
        security: [['jwtBearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'room_id', type: 'string', maxLength: 26, description: 'Cômodo ativo da residência ativa'),
            new OA\Property(property: 'name', type: 'string', maxLength: 120, example: 'Ar-condicionado'),
            new OA\Property(property: 'category', type: 'string', maxLength: 40, nullable: true, example: 'eletro'),
            new OA\Property(property: 'brand', type: 'string', maxLength: 60, nullable: true, example: 'Springer'),
            new OA\Property(property: 'model', type: 'string', maxLength: 60, nullable: true, example: 'X-12000'),
            new OA\Property(property: 'acquired_at', type: 'string', format: 'date', nullable: true, example: '2024-01-15'),
            new OA\Property(property: 'warranty_until', type: 'string', format: 'date', nullable: true, example: '2027-03-15'),
            new OA\Property(property: 'metadata', type: 'object', nullable: true, additionalProperties: true),
        ], required: ['room_id', 'name'])),
        responses: [
            new OA\Response(response: 201, description: 'Ativo criado', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: AssetSchema::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Capability assets.manage ausente', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Cômodo não encontrado ou arquivado', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function store(StoreAssetRequest $request): JsonResponse
    {
        return response()->json($this->assets->store($request), 201);
    }

    #[OA\Patch(
        path: '/api/v1/maintenance/assets/{asset}',
        summary: 'Atualiza, transfere de cômodo ou arquiva um ativo',
        tags: ['maintenance'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\PathParameter(parameter: 'asset', description: 'Id ULID do ativo', required: true, schema: new OA\Schema(type: 'string', maxLength: 26))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'room_id', type: 'string', maxLength: 26, description: 'Destino da transferência; precisa estar ativo'),
            new OA\Property(property: 'name', type: 'string', maxLength: 120),
            new OA\Property(property: 'category', type: 'string', maxLength: 40, nullable: true),
            new OA\Property(property: 'brand', type: 'string', maxLength: 60, nullable: true),
            new OA\Property(property: 'model', type: 'string', maxLength: 60, nullable: true),
            new OA\Property(property: 'acquired_at', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'warranty_until', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'metadata', type: 'object', nullable: true, additionalProperties: true),
            new OA\Property(property: 'archived', type: 'boolean', description: 'true arquiva; false restaura'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Ativo atualizado', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: AssetSchema::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Capability assets.manage ausente', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Ativo ou cômodo de destino não encontrado', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function update(UpdateAssetRequest $request, string $asset): JsonResponse
    {
        return response()->json($this->assets->update($request, $asset));
    }

    #[OA\Delete(
        path: '/api/v1/maintenance/assets/{asset}',
        summary: 'Arquiva um ativo',
        tags: ['maintenance'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\PathParameter(parameter: 'asset', description: 'Id ULID do ativo', required: true, schema: new OA\Schema(type: 'string', maxLength: 26))],
        responses: [
            new OA\Response(response: 200, description: 'Ativo arquivado', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: AssetSchema::class),
                new OA\Property(property: 'archived', type: 'boolean', example: true),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Capability assets.manage ausente', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Ativo não encontrado na residência ativa', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function destroy(DestroyAssetRequest $request, string $asset): JsonResponse
    {
        return response()->json($this->assets->destroy($request, $asset));
    }
}
