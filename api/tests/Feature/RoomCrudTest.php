<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Role;
use Mordomus\Identity\Models\User;
use Mordomus\Maintenance\Models\Room;

/**
 * T2.1 — CRUD de cômodos: isolamento por residência (AC 1), ordenação
 * persistida e refletida na API (AC 2), arquivamento lógico e RBAC.
 */
class RoomCrudTest extends FeatureTestCase
{
    private string $tenantId;

    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        $auth = $this->registerUser('comodos@mordomus.test', 'Comodos', 'Casa A');
        $this->tenantId = $auth['active_tenant'];
        $this->headers = [
            'Authorization' => 'Bearer '.$auth['access_token'],
            'Accept' => 'application/json',
        ];
    }

    public function test_owner_creates_lists_and_shows_rooms(): void
    {
        $sala = $this->withHeaders($this->headers)->postJson('/api/v1/maintenance/rooms', [
            'name' => 'Sala',
            'icon' => 'sofa',
        ]);
        $sala->assertCreated()->assertJsonPath('data.name', 'Sala')
            ->assertJsonPath('data.icon', 'sofa')
            ->assertJsonPath('data.archived', false)
            ->assertJsonPath('data.tenant_id', $this->tenantId);

        $cozinha = $this->withHeaders($this->headers)->postJson('/api/v1/maintenance/rooms', ['name' => 'Cozinha']);
        $cozinha->assertCreated();

        $list = $this->withHeaders($this->headers)->getJson('/api/v1/maintenance/rooms');
        $list->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(['Sala', 'Cozinha'], collect($list->json('data'))->pluck('name')->all());
        $this->assertSame(2, $list->json('meta.total'));

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/maintenance/rooms/'.$sala->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.id', $sala->json('data.id'));
    }

    /** AC 1 — cada residência vê apenas os próprios cômodos. */
    public function test_rooms_are_isolated_between_tenants(): void
    {
        $mine = $this->withHeaders($this->headers)
            ->postJson('/api/v1/maintenance/rooms', ['name' => 'Minha Sala'])
            ->assertCreated()
            ->json('data.id');

        $other = $this->registerUser('outra-casa@mordomus.test', 'Outra', 'Casa B');
        $foreign = Room::create([
            'tenant_id' => $other['active_tenant'],
            'name' => 'Sala da Casa B',
            'sort_order' => 1,
        ]);

        $list = $this->withHeaders($this->headers)->getJson('/api/v1/maintenance/rooms');
        $list->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame([$mine], collect($list->json('data'))->pluck('id')->all());

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/maintenance/rooms/'.$foreign->id)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');

        $this->withHeaders($this->headers)
            ->patchJson('/api/v1/maintenance/rooms/'.$foreign->id, ['name' => 'Invadida'])
            ->assertStatus(404);

        $this->withHeaders($this->headers)
            ->deleteJson('/api/v1/maintenance/rooms/'.$foreign->id)
            ->assertStatus(404);

        $stillThere = DB::table('rooms')->where('id', $foreign->id)->first();
        $this->assertNotNull($stillThere);
        $this->assertNull($stillThere->archived_at);
        $this->assertSame('Sala da Casa B', $stillThere->name);
    }

    /** AC 2 — a ordenação é persistida e devolvida nessa ordem pela API. */
    public function test_listing_reflects_persisted_order(): void
    {
        $ids = [];
        foreach (['Quarto', 'Sala', 'Garagem'] as $name) {
            $ids[] = $this->withHeaders($this->headers)
                ->postJson('/api/v1/maintenance/rooms', ['name' => $name])
                ->assertCreated()
                ->json('data.id');
        }

        $this->withHeaders($this->headers)
            ->putJson('/api/v1/maintenance/rooms/order', ['ids' => [$ids[2], $ids[1], $ids[0]]])
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $persisted = DB::table('rooms')
            ->where('tenant_id', $this->tenantId)
            ->orderBy('sort_order')
            ->pluck('id', 'sort_order')
            ->all();
        $this->assertSame([$ids[2], $ids[1], $ids[0]], array_values($persisted));

        $list = $this->withHeaders($this->headers)->getJson('/api/v1/maintenance/rooms');
        $list->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame(
            ['Garagem', 'Sala', 'Quarto'],
            collect($list->json('data'))->pluck('name')->all(),
        );
    }

    public function test_reorder_requires_exactly_the_active_rooms_of_the_tenant(): void
    {
        $a = $this->withHeaders($this->headers)
            ->postJson('/api/v1/maintenance/rooms', ['name' => 'A'])->json('data.id');
        $b = $this->withHeaders($this->headers)
            ->postJson('/api/v1/maintenance/rooms', ['name' => 'B'])->json('data.id');

        // lista parcial
        $this->withHeaders($this->headers)
            ->putJson('/api/v1/maintenance/rooms/order', ['ids' => [$b]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        // id de fora da residência
        $other = $this->registerUser('terceira@mordomus.test', 'Terceira', 'Casa C');
        $foreignId = Room::create([
            'tenant_id' => $other['active_tenant'],
            'name' => 'Cômodo C',
            'sort_order' => 1,
        ])->id;

        $this->withHeaders($this->headers)
            ->putJson('/api/v1/maintenance/rooms/order', ['ids' => [$a, $b, $foreignId]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        // nada foi reordenado
        $persisted = DB::table('rooms')->where('tenant_id', $this->tenantId)->orderBy('sort_order')->pluck('sort_order');
        $this->assertSame([1, 2], $persisted->all());
    }

    /** ADR-006 — `DELETE` arquiva; a linha permanece no banco. */
    public function test_delete_archives_logically_and_hides_from_list(): void
    {
        $id = $this->withHeaders($this->headers)
            ->postJson('/api/v1/maintenance/rooms', ['name' => 'Despensa'])
            ->assertCreated()
            ->json('data.id');

        $deleted = $this->withHeaders($this->headers)->deleteJson('/api/v1/maintenance/rooms/'.$id);
        $deleted->assertOk()
            ->assertJsonPath('data.archived', true)
            ->assertJsonPath('archived', true);

        $row = DB::table('rooms')->where('id', $id)->first();
        $this->assertNotNull($row, 'arquivamento lógico não pode apagar a linha');
        $this->assertNotNull($row->archived_at);

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/maintenance/rooms')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/maintenance/rooms?include_archived=1')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_archived_room_can_be_restored(): void
    {
        $id = $this->withHeaders($this->headers)
            ->postJson('/api/v1/maintenance/rooms', ['name' => 'Varanda'])
            ->assertCreated()
            ->json('data.id');

        $this->withHeaders($this->headers)->deleteJson('/api/v1/maintenance/rooms/'.$id)->assertOk();

        $this->withHeaders($this->headers)
            ->patchJson('/api/v1/maintenance/rooms/'.$id, ['archived' => false])
            ->assertOk()
            ->assertJsonPath('data.archived', false);

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/maintenance/rooms')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /** ADR-007 — member não tem `rooms.manage`; leitura segue liberada. */
    public function test_member_without_rooms_manage_can_read_but_not_write(): void
    {
        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'tenant_id' => $this->tenantId,
            'role_id' => Role::systemByKey(Role::MEMBER)->id,
            'status' => Membership::STATUS_ACTIVE,
        ]);

        $this->asUser($member, $this->tenantId)
            ->getJson('/api/v1/maintenance/rooms')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $write = $this->asUser($member, $this->tenantId)
            ->postJson('/api/v1/maintenance/rooms', ['name' => 'Sem Permissão']);
        $write->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden')
            ->assertJsonPath('error.details.required', 'rooms.manage');

        $this->asUser($member, $this->tenantId)
            ->putJson('/api/v1/maintenance/rooms/order', ['ids' => [Str::ulid()]])
            ->assertStatus(403);

        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_name_is_required_and_update_needs_at_least_one_field(): void
    {
        $this->withHeaders($this->headers)
            ->postJson('/api/v1/maintenance/rooms', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        $id = $this->withHeaders($this->headers)
            ->postJson('/api/v1/maintenance/rooms', ['name' => 'Quintal'])
            ->assertCreated()
            ->json('data.id');

        $this->withHeaders($this->headers)
            ->patchJson('/api/v1/maintenance/rooms/'.$id, [])
            ->assertStatus(422);

        $this->withHeaders($this->headers)
            ->patchJson('/api/v1/maintenance/rooms/'.$id, ['name' => '  '])
            ->assertStatus(422);

        $this->withHeaders($this->headers)
            ->patchJson('/api/v1/maintenance/rooms/'.$id, ['name' => 'Quintal Ampliado'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Quintal Ampliado');
    }

    public function test_list_requires_authentication(): void
    {
        $this->getJson('/api/v1/maintenance/rooms')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }
}
