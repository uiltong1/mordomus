<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Identity\Models\User;
use Mordomus\Maintenance\Models\Asset;
use Mordomus\Maintenance\Models\Room;

/**
 * Esquema do motor de agendamento.
 *
 * O que se verifica não é o tipo de cada coluna, e sim as garantias que o
 * banco precisa dar sozinho: alvo do trigger com exclusão mútua, um título
 * por alvo, uma ocorrência por data (é o que torna o recálculo idempotente),
 * referência real para as linhas de cima e o destino das linhas filhas quando
 * o pai é removido.
 *
 * As inserções são feitas por `DB::table` porque os models dessas tabelas
 * ainda não existem — este teste é sobre o esquema, não sobre a camada de
 * acesso.
 */
class ScheduleSchemaTest extends FeatureTestCase
{
    private string $tenantId;

    private string $roomId;

    private string $assetId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = Tenant::factory()->create()->id;
        $this->roomId = Room::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Sala',
            'sort_order' => 0,
        ])->id;
        $this->assetId = $this->createAsset('Ar-condicionado');
    }

    public function test_tables_carry_the_columns_of_the_contract(): void
    {
        $this->assertTrue(Schema::hasColumns('trigger_configs', [
            'id', 'tenant_id', 'subject_type', 'asset_id', 'bill_id', 'title', 'description',
            'is_active', 'type', 'interval_value', 'interval_unit', 'day_of_month',
            'advance_notice_days', 'recalculate_base', 'custom_offsets', 'preferred_hour',
            'last_base_date', 'next_due_at', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('job_schedules', [
            'id', 'tenant_id', 'trigger_config_id', 'scheduled_for', 'due_at', 'status',
            'notified_at', 'completed_at', 'completed_by', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('schedule_events', [
            'id', 'tenant_id', 'job_schedule_id', 'event', 'actor', 'payload', 'occurred_at',
        ]));
    }

    /** A trilha é append-only: `occurred_at` é o único relógio da linha. */
    public function test_schedule_events_has_no_update_clock(): void
    {
        $this->assertTrue(Schema::hasColumn('schedule_events', 'occurred_at'));
        $this->assertFalse(Schema::hasColumn('schedule_events', 'created_at'));
        $this->assertFalse(Schema::hasColumn('schedule_events', 'updated_at'));
        $this->assertFalse(Schema::hasColumn('schedule_events', 'archived_at'));
    }

    public function test_trigger_config_points_to_exactly_one_target(): void
    {
        $this->insertTriggerConfig(['title' => 'Trocar o filtro']);

        $this->assertRejected(fn () => $this->insertTriggerConfig([
            'title' => 'Sem alvo nenhum',
            'asset_id' => null,
        ]));

        $this->assertRejected(fn () => $this->insertTriggerConfig([
            'title' => 'Com os dois alvos',
            'bill_id' => (string) Str::ulid(),
        ]));

        $this->assertRejected(fn () => $this->insertTriggerConfig([
            'title' => 'Alvo trocado',
            'subject_type' => 'bill',
        ]));

        $this->insertTriggerConfig([
            'title' => 'Conta de luz',
            'subject_type' => 'bill',
            'asset_id' => null,
            'bill_id' => (string) Str::ulid(),
        ]);

        $this->assertSame(2, DB::table('trigger_configs')->count());
    }

    public function test_trigger_config_title_is_unique_per_target(): void
    {
        $this->insertTriggerConfig(['title' => 'Trocar o filtro']);

        $this->assertRejected(fn () => $this->insertTriggerConfig(['title' => 'Trocar o filtro']));

        $this->insertTriggerConfig([
            'title' => 'Trocar o filtro',
            'asset_id' => $this->createAsset('Filtro do ar'),
        ]);

        $this->insertTriggerConfig([
            'title' => 'Trocar o filtro',
            'subject_type' => 'bill',
            'asset_id' => null,
            'bill_id' => (string) Str::ulid(),
        ]);

        $this->assertSame(3, DB::table('trigger_configs')->count());
    }

    /** Concluir duas vezes tenta a mesma data para o mesmo trigger — o banco recusa. */
    public function test_a_trigger_has_at_most_one_occurrence_per_date(): void
    {
        $configId = $this->insertTriggerConfig();

        $this->insertJobSchedule($configId, '2026-10-01');

        $this->assertRejected(fn () => $this->insertJobSchedule($configId, '2026-10-01'));

        $this->insertJobSchedule($configId, '2026-10-08');
        $this->insertJobSchedule($this->insertTriggerConfig(['title' => 'Outro título']), '2026-10-01');

        $this->assertSame(3, DB::table('job_schedules')->count());
    }

    public function test_occurrence_and_event_refer_to_rows_that_exist(): void
    {
        $this->assertRejected(fn () => $this->insertJobSchedule((string) Str::ulid(), '2026-10-01'));
        $this->assertRejected(fn () => $this->insertScheduleEvent((string) Str::ulid()));

        $scheduleId = $this->insertJobSchedule($this->insertTriggerConfig(), '2026-10-01');
        $this->insertScheduleEvent($scheduleId);

        $this->assertSame(1, DB::table('job_schedules')->count());
        $this->assertSame(1, DB::table('schedule_events')->count());
    }

    public function test_status_and_event_are_constrained_by_the_database(): void
    {
        $configId = $this->insertTriggerConfig();
        $scheduleId = $this->insertJobSchedule($configId, '2026-10-01');

        $this->assertRejected(fn () => $this->insertJobSchedule($configId, '2026-10-08', ['status' => 'cancelled']));
        $this->assertRejected(fn () => $this->insertScheduleEvent($scheduleId, ['event' => 'DONE']));

        $this->insertJobSchedule($configId, '2026-10-08', ['status' => 'overdue']);
        $this->insertScheduleEvent($scheduleId, ['event' => 'SKIPPED', 'actor' => null]);

        $this->assertSame(2, DB::table('job_schedules')->count());
        $this->assertSame(1, DB::table('schedule_events')->count());
    }

    public function test_deleting_the_target_removes_the_whole_cycle(): void
    {
        $scheduleId = $this->insertJobSchedule($this->insertTriggerConfig(), '2026-10-01');
        $this->insertScheduleEvent($scheduleId);

        DB::table('assets')->where('id', $this->assetId)->delete();

        $this->assertSame(0, DB::table('trigger_configs')->count());
        $this->assertSame(0, DB::table('job_schedules')->count());
        $this->assertSame(0, DB::table('schedule_events')->count());
    }

    public function test_deleting_a_user_keeps_the_history_without_the_actor(): void
    {
        $user = User::factory()->create();

        $scheduleId = $this->insertJobSchedule($this->insertTriggerConfig(), '2026-10-01', [
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by' => $user->id,
        ]);
        $this->insertScheduleEvent($scheduleId, ['event' => 'COMPLETED', 'actor' => $user->id]);

        DB::table('users')->where('id', $user->id)->delete();

        $this->assertNull(DB::table('job_schedules')->where('id', $scheduleId)->value('completed_by'));
        $this->assertNull(DB::table('schedule_events')->where('job_schedule_id', $scheduleId)->value('actor'));
        $this->assertSame(1, DB::table('job_schedules')->count());
    }

    public function test_an_event_emitted_by_the_queue_carries_no_actor(): void
    {
        $scheduleId = $this->insertJobSchedule($this->insertTriggerConfig(), '2026-10-01');
        $eventId = $this->insertScheduleEvent($scheduleId, [
            'event' => 'NOTIFIED',
            'payload' => json_encode(['channel' => 'push'], JSON_THROW_ON_ERROR),
        ]);

        $this->assertNull(DB::table('schedule_events')->where('id', $eventId)->value('actor'));
        $this->assertSame(
            'push',
            json_decode((string) DB::table('schedule_events')->where('id', $eventId)->value('payload'), true)['channel']
        );
    }

    private function createAsset(string $name): string
    {
        return Asset::create([
            'tenant_id' => $this->tenantId,
            'room_id' => $this->roomId,
            'name' => $name,
        ])->id;
    }

    private function insertTriggerConfig(array $overrides = []): string
    {
        $id = (string) Str::ulid();

        DB::table('trigger_configs')->insert($overrides + [
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'subject_type' => 'asset',
            'asset_id' => $this->assetId,
            'bill_id' => null,
            'title' => 'Limpeza do filtro',
            'type' => 'INTERVAL',
            'interval_value' => 90,
            'interval_unit' => 'days',
            'is_active' => true,
            'advance_notice_days' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertJobSchedule(string $triggerConfigId, string $scheduledFor, array $overrides = []): string
    {
        $id = (string) Str::ulid();

        DB::table('job_schedules')->insert($overrides + [
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'trigger_config_id' => $triggerConfigId,
            'scheduled_for' => $scheduledFor,
            'due_at' => $scheduledFor.' 09:00:00',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertScheduleEvent(string $jobScheduleId, array $overrides = []): string
    {
        $id = (string) Str::ulid();

        DB::table('schedule_events')->insert($overrides + [
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'job_schedule_id' => $jobScheduleId,
            'event' => 'CREATED',
            'actor' => null,
            'payload' => null,
            'occurred_at' => now(),
        ]);

        return $id;
    }

    private function assertRejected(callable $write): void
    {
        try {
            $write();
        } catch (QueryException) {
            return;
        }

        $this->fail('O banco aceitou uma escrita que viola o esquema.');
    }
}
