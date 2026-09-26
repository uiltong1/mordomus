<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Role;
use Mordomus\Identity\Models\User;
use Mordomus\Maintenance\Models\Asset;
use Mordomus\Maintenance\Models\Room;

/**
 * Inventário de ativos: CRUD com `room_id`, filtros/paginação,
 * transferência entre cômodos, isolamento por residência (AC 1) e datas no
 * fuso do tenant (AC 2).
 */
class AssetCrudTest extends FeatureTestCase
{
    private string $tenantId;

    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        $auth = $this->registerUser('ativos@mordomus.test', 'Ativos', 'Casa A');
        $this->tenantId = $auth['active_tenant'];
        $this->headers = [
            'Authorization' => 'Bearer '.$auth['access_token'],
            'Accept' => 'application/json',
        ];
    }

    public function test_owner_creates_lists_and_shows_asset(): void
    {
        $roomId = $this->createRoom('Sala');

        $created = $this->withHeaders($this->headers)->postJson('/api/v1/maintenance/assets', [
            'room_id' => $roomId,
            'name' => 'Ar-condicionado Split 12k',
            'category' => 'ar-condicionado',
            'brand' => 'Springer',
            'model' => 'Midea 12k',
            'acquired_at' => '2024-01-15',
            'warranty_until' => '2027-01-15',
            'metadata' => ['serial' => 'ABC123', 'watts' => 1200],
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.name', 'Ar-condicionado Split 12k')
            ->assertJsonPath('data.category', 'ar-condicionado')
            ->assertJsonPath('data.room_id', $roomId)
            ->assertJsonPath('data.tenant_id', $this->tenantId)
            ->assertJsonPath('data.metadata.serial', 'ABC123')
            ->assertJsonPath('data.archived', false);

        $assetId = $created->json('data.id');

        $list = $this->withHeaders($this->headers)->getJson('/api/v1/maintenance/assets');
        $list->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(1, $list->json('meta.total'));

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/maintenance/assets/'.$assetId)
            ->assertOk()
            ->assertJsonPath('data.id', $assetId);
    }

    /** AC 1 — o ativo só existe dentro de cômodo da mesma residência. */
    public function test_asset_only_exists_inside_a_room_of_the_same_tenant(): void
    {
        $other = $this->registerUser('outra-casa-ativos@mordomus.test', 'Outra', 'Casa B');
        $foreignRoom = Room::create([
            'tenant_id' => $other['active_tenant'],
            'name' => 'Sala da Casa B',
            'sort_order' => 1,
        ]);

        // ativo não nasce em cômodo de outra residência
        $denied = $this->withHeaders($this->headers)->postJson('/api/v1/maintenance/assets', [
            'room_id' => $foreignRoom->id,
            'name' => 'Invasão',
        ]);
        $denied->assertStatus(404)
            ->assertJsonPath('error.code', 'room_not_found');
        $this->assertDatabaseCount('assets', 0);

        // ativo já existente em outra residência fica inacessível
        $foreign = Asset::create([
            'tenant_id' => $other['active_tenant'],
            'room_id' => $foreignRoom->id,
            'name' => 'Sofá da Casa B',
            'category' => 'movel',
        ]);

        $mine = $this->createRoom('Minha Sala');
        $this->withHeaders($this->headers)->postJson('/api/v1/maintenance/assets', [
            'room_id' => $mine,
            'name' => 'Meu Sofá',
        ])->assertCreated();

        $list = $this->withHeaders($this->headers)->getJson('/api/v1/maintenance/assets');
        $list->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Meu Sofá', $list->json('data.0.name'));

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/maintenance/assets/'.$foreign->id)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');

        $this->withHeaders($this->headers)
            ->patchJson('/api/v1/maintenance/assets/'.$foreign->id, ['name' => 'Sequestrado'])
            ->assertStatus(404);

        $this->withHeaders($this->headers)
            ->deleteJson('/api/v1/maintenance/assets/'.$foreign->id)
            ->assertStatus(404);

        $row = DB::table('assets')->where('id', $foreign->id)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->archived_at);
        $this->assertSame('Sofá da Casa B', $row->name);
    }

    public function test_transfer_between_rooms_only_works_inside_the_tenant(): void
    {
        $livingRoom = $this->createRoom('Sala');
        $bedroom = $this->createRoom('Quarto');

        $assetId = $this->withHeaders($this->headers)->postJson('/api/v1/maintenance/assets', [
            'room_id' => $livingRoom,
            'name' => 'Colchão',
            'category' => 'movel',
        ])->assertCreated()->json('data.id');

        $moved = $this->withHeaders($this->headers)
            ->patchJson('/api/v1/maintenance/assets/'.$assetId, ['room_id' => $bedroom]);
        $moved->assertOk()->assertJsonPath('data.room_id', $bedroom);

        // cômodo de outra residência
        $other = $this->registerUser('outra-transferencia@mordomus.test', 'Outra', 'Casa B');
        $foreignRoom = Room::create([
            'tenant_id' => $other['active_tenant'],
            'name' => 'Sala da Casa B',
            'sort_order' => 1,
        ]);

        $this->withHeaders($this->headers)
            ->patchJson('/api/v1/maintenance/assets/'.$assetId, ['room_id' => $foreignRoom->id])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'room_not_found');

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/maintenance/assets/'.$assetId)
            ->assertOk()
            ->assertJsonPath('data.room_id', $bedroom);
    }

    public function test_filter_by_room_and_category_with_offset_pagination(): void
    {
        $roomA = $this->createRoom('Sala');
        $roomB = $this->createRoom('Cozinha');

        $this->createAsset($roomA, 'Ar-condicionado', 'ar-condicionado');
        $this->createAsset($roomA, 'Sofá', 'movel');
        $this->createAsset($roomB, 'Fogão', 'eletro');

        $byRoom = $this->withHeaders($this->headers)->getJson('/api/v1/maintenance/assets?room_id='.$roomA);
        $byRoom->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(2, $byRoom->json('meta.total'));

        $byCategory = $this->withHeaders($this->headers)->getJson('/api/v1/maintenance/assets?category=ar-condicionado');
        $byCategory->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Ar-condicionado', $byCategory->json('data.0.name'));

        $both = $this->withHeaders($this->headers)
            ->getJson('/api/v1/maintenance/assets?room_id='.$roomB.'&category=movel');
        $both->assertOk()->assertJsonCount(0, 'data');

        $page = $this->withHeaders($this->headers)->getJson('/api/v1/maintenance/assets?page=2&per_page=2');
        $page->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(3, $page->json('meta.total'));
        $this->assertSame(2, $page->json('meta.page'));
        $this->assertSame(2, $page->json('meta.last_page'));
        $this->assertSame(2, $page->json('meta.per_page'));
    }

    /** AC 2 — a mesma data vira outro offset conforme o fuso da residência. */
    public function test_dates_are_formatted_in_the_tenants_timezone(): void
    {
        // residência A: fuso padrão America/Sao_Paulo
        $roomA = $this->createRoom('Sala');
        $saoPaulo = $this->withHeaders($this->headers)->postJson('/api/v1/maintenance/assets', [
            'room_id' => $roomA,
            'name' => 'Ar-condicionado',
            'warranty_until' => '2027-03-15',
        ])->assertCreated()->json('data.warranty_until');

        $this->assertSame('2027-03-15T00:00:00-03:00', $saoPaulo);

        // residência B: explicitamente em UTC
        $authB = $this->registerUser('utc@casa-mordomus.test', 'UTC', 'Casa UTC');
        $headersB = ['Authorization' => 'Bearer '.$authB['access_token'], 'Accept' => 'application/json'];

        $tenantUtc = $this->withHeaders($headersB)
            ->postJson('/api/v1/identity/tenants', ['name' => 'Casa UTC', 'timezone' => 'UTC'])
            ->assertCreated()
            ->json('data.id');

        $switched = $this->withHeaders($headersB)
            ->postJson('/api/v1/identity/auth/switch-tenant', ['tenant_id' => $tenantUtc])
            ->assertOk();
        $headersB['Authorization'] = 'Bearer '.$switched->json('access_token');

        $roomB = $this->withHeaders($headersB)
            ->postJson('/api/v1/maintenance/rooms', ['name' => 'Sala'])
            ->assertCreated()
            ->json('data.id');

        $utc = $this->withHeaders($headersB)->postJson('/api/v1/maintenance/assets', [
            'room_id' => $roomB,
            'name' => 'Ar-condicionado',
            'warranty_until' => '2027-03-15',
        ])->assertCreated()->json('data.warranty_until');

        $this->assertSame('2027-03-15T00:00:00+00:00', $utc);

        // "meia-noite de 15/03" é um instante diferente em cada fuso — e a
        // leitura de volta devolve o mesmo calendário de cada residência.
        $this->withHeaders($this->headers)
            ->getJson('/api/v1/maintenance/assets?room_id='.$roomA)
            ->assertOk()
            ->assertJsonPath('data.0.warranty_until', '2027-03-15T00:00:00-03:00');

        $this->withHeaders($headersB)
            ->getJson('/api/v1/maintenance/assets?room_id='.$roomB)
            ->assertOk()
            ->assertJsonPath('data.0.warranty_until', '2027-03-15T00:00:00+00:00');
    }

    /** member não tem `assets.manage`; leitura segue liberada. */
    public function test_member_without_assets_manage_cannot_write(): void
    {
        $roomId = $this->createRoom('Sala');
        $member = $this->makeMember();

        $this->asUser($member, $this->tenantId)
            ->getJson('/api/v1/maintenance/assets')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $denied = $this->asUser($member, $this->tenantId)->postJson('/api/v1/maintenance/assets', [
            'room_id' => $roomId,
            'name' => 'Sem Permissão',
        ]);
        $denied->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden')
            ->assertJsonPath('error.details.required', 'assets.manage');

        $this->assertDatabaseCount('assets', 0);
    }

    public function test_asset_is_archived_logically_and_can_be_restored(): void
    {
        $roomId = $this->createRoom('Sala');
        $assetId = $this->createAsset($roomId, 'Filtro de água', 'filtro');

        $deleted = $this->withHeaders($this->headers)->deleteJson('/api/v1/maintenance/assets/'.$assetId);
        $deleted->assertOk()
            ->assertJsonPath('data.archived', true)
            ->assertJsonPath('archived', true);

        $row = DB::table('assets')->where('id', $assetId)->first();
        $this->assertNotNull($row, 'arquivamento lógico não pode apagar a linha');
        $this->assertNotNull($row->archived_at);

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/maintenance/assets')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/maintenance/assets?include_archived=1')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withHeaders($this->headers)
            ->patchJson('/api/v1/maintenance/assets/'.$assetId, ['archived' => false])
            ->assertOk()
            ->assertJsonPath('data.archived', false);

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/maintenance/assets')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_archived_room_cannot_receive_new_assets(): void
    {
        $roomId = $this->createRoom('Sala');
        $this->withHeaders($this->headers)
            ->deleteJson('/api/v1/maintenance/rooms/'.$roomId)
            ->assertOk();

        $this->withHeaders($this->headers)
            ->postJson('/api/v1/maintenance/assets', ['room_id' => $roomId, 'name' => 'Órfão'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'room_not_found');

        $this->assertDatabaseCount('assets', 0);
    }

    public function test_validation_of_required_fields_and_payload(): void
    {
        $roomId = $this->createRoom('Sala');

        $this->withHeaders($this->headers)
            ->postJson('/api/v1/maintenance/assets', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        $this->withHeaders($this->headers)
            ->postJson('/api/v1/maintenance/assets', ['room_id' => $roomId, 'name' => ''])
            ->assertStatus(422);

        $this->withHeaders($this->headers)
            ->postJson('/api/v1/maintenance/assets', [
                'room_id' => $roomId,
                'name' => 'Filtro',
                'metadata' => 'não é objeto',
            ])
            ->assertStatus(422);

        $assetId = $this->createAsset($roomId, 'Filtro', 'filtro');

        $this->withHeaders($this->headers)
            ->patchJson('/api/v1/maintenance/assets/'.$assetId, [])
            ->assertStatus(422);

        $this->withHeaders($this->headers)
            ->patchJson('/api/v1/maintenance/assets/'.$assetId, ['name' => 'Filtro de barro'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Filtro de barro');
    }

    public function test_list_requires_authentication(): void
    {
        $this->getJson('/api/v1/maintenance/assets')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    private function createRoom(string $name): string
    {
        return $this->withHeaders($this->headers)
            ->postJson('/api/v1/maintenance/rooms', ['name' => $name])
            ->assertCreated()
            ->json('data.id');
    }

    private function createAsset(string $roomId, string $name, string $category): string
    {
        return $this->withHeaders($this->headers)
            ->postJson('/api/v1/maintenance/assets', [
                'room_id' => $roomId,
                'name' => $name,
                'category' => $category,
            ])
            ->assertCreated()
            ->json('data.id');
    }

    private function makeMember(): User
    {
        $member = User::factory()->create();

        Membership::create([
            'user_id' => $member->id,
            'tenant_id' => $this->tenantId,
            'role_id' => Role::systemByKey(Role::MEMBER)->id,
            'status' => Membership::STATUS_ACTIVE,
        ]);

        return $member;
    }
}
