<?php

namespace Mordomus\Maintenance\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mordomus\Http\Controllers\Controller;
use Mordomus\Http\Pagination\OffsetPagination;
use Mordomus\Maintenance\Http\Presenters\RoomPresenter;
use Mordomus\Maintenance\Http\Requests\ReorderRoomsRequest;
use Mordomus\Maintenance\Http\Requests\StoreRoomRequest;
use Mordomus\Maintenance\Http\Requests\UpdateRoomRequest;
use Mordomus\Maintenance\Models\Room;
use Mordomus\Maintenance\Services\RoomOrder;
use Mordomus\OpenApi\Schemas\Error;
use Mordomus\OpenApi\Schemas\PageMeta;
use Mordomus\OpenApi\Schemas\Room as RoomSchema;
use OpenApi\Attributes as OA;

/**
 * Todas as queries passam pelo escopo global do tenant; um id de outra
 * residência simplesmente não existe aqui e vira 404.
 */
class RoomController extends Controller
{
    public function __construct(
        private readonly RoomPresenter $presenter,
        private readonly RoomOrder $order,
    ) {}

    #[OA\Get(
        path: '/api/v1/maintenance/rooms',
        summary: 'Lista os cômodos da residência ativa',
        tags: ['maintenance'],
        security: [['jwtBearerAuth' => []]],
        parameters: [
            new OA\Parameter(parameter: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
            new OA\Parameter(parameter: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 25, minimum: 1, maximum: 100)),
            new OA\Parameter(parameter: 'include_archived', in: 'query', schema: new OA\Schema(type: 'boolean', default: false)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cômodos paginados em ordem de exibição', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: RoomSchema::class)),
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

        $rooms = $this->baseQuery($request)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($pagination->perPage, ['*'], 'page', $pagination->page);

        return response()->json([
            'data' => $this->presenter->collection($rooms->getCollection()),
            'meta' => $pagination->meta($rooms->total(), $rooms->lastPage()),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/maintenance/rooms/{room}',
        summary: 'Detalhe de um cômodo',
        tags: ['maintenance'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\PathParameter(parameter: 'room', description: 'Id ULID do cômodo', required: true, schema: new OA\Schema(type: 'string', maxLength: 26))],
        responses: [
            new OA\Response(response: 200, description: 'Cômodo', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: RoomSchema::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Sem residência ativa no token', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Cômodo não encontrado na residência ativa', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function show(Request $request, string $room): JsonResponse
    {
        return response()->json([
            'data' => $this->presenter->make($this->findOrFail($room, $request)),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/maintenance/rooms',
        summary: 'Cria um cômodo',
        tags: ['maintenance'],
        security: [['jwtBearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'name', type: 'string', maxLength: 80, example: 'Sala de estar'),
            new OA\Property(property: 'icon', type: 'string', maxLength: 40, nullable: true, example: 'sofa'),
            new OA\Property(property: 'sort_order', type: 'integer', minimum: 0, description: 'Omitido, entra no fim da lista'),
        ], required: ['name'])),
        responses: [
            new OA\Response(response: 201, description: 'Cômodo criado', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: RoomSchema::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Capability rooms.manage ausente', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function store(StoreRoomRequest $request): JsonResponse
    {
        $sortOrder = $request->has('sort_order')
            ? $request->integer('sort_order')
            : $this->order->nextSortOrder();

        $room = Room::create([
            'name' => $request->string('name')->toString(),
            'icon' => $request->input('icon'),
            'sort_order' => $sortOrder,
        ]);

        return response()->json(['data' => $this->presenter->make($room)], 201);
    }

    #[OA\Patch(
        path: '/api/v1/maintenance/rooms/{room}',
        summary: 'Atualiza nome, ícone, posição ou arquivamento',
        tags: ['maintenance'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\PathParameter(parameter: 'room', description: 'Id ULID do cômodo', required: true, schema: new OA\Schema(type: 'string', maxLength: 26))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'name', type: 'string', maxLength: 80),
            new OA\Property(property: 'icon', type: 'string', maxLength: 40, nullable: true),
            new OA\Property(property: 'sort_order', type: 'integer', minimum: 0),
            new OA\Property(property: 'archived', type: 'boolean', description: 'true arquiva; false restaura'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Cômodo atualizado', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: RoomSchema::class),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Capability rooms.manage ausente', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Cômodo não encontrado na residência ativa', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Dados inválidos', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function update(UpdateRoomRequest $request, string $room): JsonResponse
    {
        $room = $this->findOrFail($room, $request);
        $room->fill($request->only(['name', 'icon', 'sort_order']));
        $room->save();

        if ($request->has('archived')) {
            $request->boolean('archived') ? $room->archive() : $room->restore();
            $room->refresh();
        }

        return response()->json(['data' => $this->presenter->make($room)]);
    }

    #[OA\Delete(
        path: '/api/v1/maintenance/rooms/{room}',
        summary: 'Arquiva o cômodo',
        tags: ['maintenance'],
        security: [['jwtBearerAuth' => []]],
        parameters: [new OA\PathParameter(parameter: 'room', description: 'Id ULID do cômodo', required: true, schema: new OA\Schema(type: 'string', maxLength: 26))],
        responses: [
            new OA\Response(response: 200, description: 'Cômodo arquivado', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: RoomSchema::class),
                new OA\Property(property: 'archived', type: 'boolean', example: true),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Capability rooms.manage ausente', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 404, description: 'Cômodo não encontrado na residência ativa', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function destroy(Request $request, string $room): JsonResponse
    {
        $room = $this->findOrFail($room, $request);
        $room->archive();

        return response()->json(['data' => $this->presenter->make($room), 'archived' => true]);
    }

    #[OA\Put(
        path: '/api/v1/maintenance/rooms/order',
        summary: 'Reordena os cômodos com a lista completa de ids',
        tags: ['maintenance'],
        security: [['jwtBearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'ids', type: 'array', items: new OA\Items(type: 'string', maxLength: 26), minItems: 1),
        ], required: ['ids'])),
        responses: [
            new OA\Response(response: 200, description: 'Ordem aplicada', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: RoomSchema::class)),
            ])),
            new OA\Response(response: 401, description: 'Token ausente ou inválido', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 403, description: 'Capability rooms.manage ausente', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 422, description: 'Lista fora do esperado ou dados inválidos', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 429, description: 'Rate limit do gateway', content: new OA\JsonContent(ref: Error::class)),
            new OA\Response(response: 500, description: 'Erro interno', content: new OA\JsonContent(ref: Error::class)),
        ],
    )]
    public function order(ReorderRoomsRequest $request): JsonResponse
    {
        $given = collect($request->input('ids'))->sort()->values()->all();
        $expected = $this->order->activeIds();

        if ($given !== $expected) {
            return $this->error(
                $request,
                422,
                'validation_failed',
                'A lista de ids precisa ser exatamente os cômodos não arquivados da residência.',
                ['expected' => $expected, 'received' => $given],
            );
        }

        $this->order->reorder($request->input('ids'));

        return response()->json([
            'data' => $this->presenter->collection($this->order->ordered()),
        ]);
    }

    private function baseQuery(Request $request): Builder
    {
        $query = Room::query();

        if ($request->boolean('include_archived')) {
            return $query;
        }

        return $query->active();
    }

    private function findOrFail(string $id, Request $request): Room
    {
        return Room::query()
            ->where('id', $id)
            ->where('tenant_id', (string) $this->activeTenantId($request))
            ->firstOrFail();
    }
}
