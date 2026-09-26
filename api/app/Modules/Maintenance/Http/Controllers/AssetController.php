<?php

namespace Mordomus\Maintenance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mordomus\Http\Controllers\Controller;
use Mordomus\Http\Pagination\OffsetPagination;
use Mordomus\Maintenance\Http\Presenters\AssetPresenter;
use Mordomus\Maintenance\Http\Requests\StoreAssetRequest;
use Mordomus\Maintenance\Http\Requests\UpdateAssetRequest;
use Mordomus\Maintenance\Models\Asset;
use Mordomus\Maintenance\Models\Room;
use Mordomus\Maintenance\Services\TenantClock;
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
    public function __construct(
        private readonly AssetPresenter $presenter,
        private readonly TenantClock $clock,
    ) {}

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
    public function index(Request $request): JsonResponse
    {
        $pagination = OffsetPagination::from($request);

        $query = Asset::query();

        if ($request->filled('room_id')) {
            $query->where('room_id', (string) $request->query('room_id'));
        }

        if ($request->filled('category')) {
            $query->where('category', (string) $request->query('category'));
        }

        if (! $request->boolean('include_archived')) {
            $query->whereNull('archived_at');
        }

        $assets = $query
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($pagination->perPage, ['*'], 'page', $pagination->page);

        $timezone = $this->clock->timezone($request);

        return response()->json([
            'data' => $this->presenter->collection($assets->getCollection(), $timezone),
            'meta' => $pagination->meta($assets->total(), $assets->lastPage()),
        ]);
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
    public function show(Request $request, string $asset): JsonResponse
    {
        $timezone = $this->clock->timezone($request);

        return response()->json([
            'data' => $this->presenter->make($this->findOrFail($asset, $request), $timezone),
        ]);
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
        $room = $this->activeRoom((string) $request->input('room_id'));

        if ($room === null) {
            return $this->roomNotFound($request);
        }

        $timezone = $this->clock->timezone($request);

        $asset = Asset::create([
            'room_id' => $room->id,
            'name' => $request->string('name')->toString(),
            'category' => $request->input('category'),
            'brand' => $request->input('brand'),
            'model' => $request->input('model'),
            'acquired_at' => $this->clock->instant($request, 'acquired_at', $timezone),
            'warranty_until' => $this->clock->instant($request, 'warranty_until', $timezone),
            'metadata' => $request->input('metadata'),
        ]);

        return response()->json(['data' => $this->presenter->make($asset, $timezone)], 201);
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
        $asset = $this->findOrFail($asset, $request);
        $timezone = $this->clock->timezone($request);

        $attributes = $request->only(['name', 'category', 'brand', 'model', 'metadata']);

        if ($request->has('room_id')) {
            $room = $this->activeRoom((string) $request->input('room_id'));

            if ($room === null) {
                return $this->roomNotFound($request);
            }

            $attributes['room_id'] = $room->id;
        }

        if ($request->has('acquired_at')) {
            $attributes['acquired_at'] = $this->clock->instant($request, 'acquired_at', $timezone);
        }

        if ($request->has('warranty_until')) {
            $attributes['warranty_until'] = $this->clock->instant($request, 'warranty_until', $timezone);
        }

        $asset->fill($attributes);
        $asset->save();

        if ($request->has('archived')) {
            $request->boolean('archived') ? $asset->archive() : $asset->restore();
            $asset->refresh();
        }

        return response()->json(['data' => $this->presenter->make($asset, $timezone)]);
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
    public function destroy(Request $request, string $asset): JsonResponse
    {
        $asset = $this->findOrFail($asset, $request);
        $asset->archive();

        return response()->json([
            'data' => $this->presenter->make($asset, $this->clock->timezone($request)),
            'archived' => true,
        ]);
    }

    /**
     * O TenantGlobalScope já esconde cômodos de outra residência; o filtro
     * de arquivados impede ativo em cômodo morto.
     */
    private function activeRoom(string $roomId): ?Room
    {
        return Room::query()->active()->find($roomId);
    }

    private function roomNotFound(Request $request): JsonResponse
    {
        return $this->error(
            $request,
            404,
            'room_not_found',
            'Cômodo não encontrado ou arquivado na residência ativa.',
            ['room_id' => $request->input('room_id')],
        );
    }

    private function findOrFail(string $id, Request $request): Asset
    {
        return Asset::query()
            ->where('id', $id)
            ->where('tenant_id', (string) $this->activeTenantId($request))
            ->firstOrFail();
    }
}
