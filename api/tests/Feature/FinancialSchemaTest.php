<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mordomus\Financial\Models\Bill;
use Mordomus\Financial\Models\BillOccurrence;
use Mordomus\Financial\Models\PaymentRecord;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Identity\Models\User;

/**
 * Esquema do módulo Financeiro.
 *
 * O que se verifica são as garantias que o banco precisa dar sozinho: um
 * vencimento por conta e data (é o que torna o consumidor idempotente),
 * referência real para a conta e para a ocorrência do Scheduling, e o destino
 * das linhas filhas quando o pai é removido — para a conta ser Levada junto
 * e o histórico de pagamento sobreviver à conta de quem pagou.
 *
 * As inserções são feitas por `DB::table` porque este teste é sobre o esquema,
 * não sobre a camada de acesso.
 */
class FinancialSchemaTest extends FeatureTestCase
{
    private string $tenantId;

    private string $billId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = Tenant::factory()->create()->id;
        $this->billId = $this->insertBill('Conta de luz');
    }

    public function test_tables_carry_the_columns_of_the_contract(): void
    {
        $this->assertTrue(Schema::hasColumns('bills', [
            'id', 'tenant_id', 'name', 'kind', 'category', 'amount', 'currency',
            'is_active', 'created_by', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('bill_occurrences', [
            'id', 'tenant_id', 'bill_id', 'schedule_id', 'due_date', 'amount',
            'status', 'paid_at', 'paid_by', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('payment_records', [
            'id', 'tenant_id', 'bill_occurrence_id', 'user_id', 'amount', 'method',
            'paid_at', 'receipt_url',
        ]));
    }

    /** A baixa de pagamento é histórico: `paid_at` é o único relógio da linha. */
    public function test_payment_records_has_no_update_clock(): void
    {
        $this->assertTrue(Schema::hasColumn('payment_records', 'paid_at'));
        $this->assertFalse(Schema::hasColumn('payment_records', 'created_at'));
        $this->assertFalse(Schema::hasColumn('payment_records', 'updated_at'));
    }

    /** `unique(bill_id, due_date)` é o que impede o evento duplicado de virar linha duplicada. */
    public function test_a_bill_has_at_most_one_due_date_per_day(): void
    {
        $occurrenceId = $this->insertOccurrence($this->billId, '2026-04-10');

        $this->assertRejected(fn () => $this->insertOccurrence($this->billId, '2026-04-10'));

        $this->insertOccurrence($this->billId, '2026-04-11');

        $this->assertSame(2, DB::table('bill_occurrences')->count());
        $this->assertNotNull(DB::table('bill_occurrences')->where('id', $occurrenceId)->first());
    }

    public function test_occurrence_and_payment_refer_to_rows_that_exist(): void
    {
        $this->assertRejected(fn () => $this->insertOccurrence((string) Str::ulid(), '2026-04-10'));
        $this->assertRejected(fn () => $this->insertPayment((string) Str::ulid(), '2026-04-10'));

        $occurrenceId = $this->insertOccurrence($this->billId, '2026-04-10');
        $this->insertPayment($occurrenceId, '2026-04-10');

        $this->assertSame(1, DB::table('bill_occurrences')->count());
        $this->assertSame(1, DB::table('payment_records')->count());
    }

    /** A FK do Scheduling é real: o vencimento aponta para a ocorrência que o motor criou. */
    public function test_the_due_date_refers_to_the_schedule_that_generated_it(): void
    {
        $scheduleId = $this->insertJobSchedule('2026-04-10');

        $this->assertRejected(fn () => $this->insertOccurrence($this->billId, '2026-04-10', (string) Str::ulid()));

        $occurrenceId = $this->insertOccurrence($this->billId, '2026-04-10', $scheduleId);

        $this->assertSame($scheduleId, DB::table('bill_occurrences')->where('id', $occurrenceId)->value('schedule_id'));

        // `nullOnDelete`: apagar a regra de recorrência tira a ligação da
        // agenda, e não o vencimento que a casa ainda deve pagar.
        DB::table('job_schedules')->where('id', $scheduleId)->delete();

        $this->assertNull(DB::table('bill_occurrences')->where('id', $occurrenceId)->value('schedule_id'));
        $this->assertSame(1, DB::table('bill_occurrences')->count());
    }

    public function test_the_regime_and_the_payment_method_are_constrained_by_the_database(): void
    {
        $this->assertRejected(fn () => $this->insertBill('Mensalidade', kind: 'monthly'));
        $this->assertRejected(fn () => $this->insertPayment(
            $this->insertOccurrence($this->billId, '2026-04-12'),
            '2026-04-12',
            method: 'bitcoin',
        ));

        $this->insertOccurrence($this->billId, '2026-04-10', status: BillOccurrence::STATUS_OVERDUE);
        $this->insertOccurrence($this->billId, '2026-04-20', status: BillOccurrence::STATUS_CANCELLED);

        $this->assertSame(
            [BillOccurrence::STATUS_OVERDUE, BillOccurrence::STATUS_OPEN, BillOccurrence::STATUS_CANCELLED],
            DB::table('bill_occurrences')->orderBy('due_date')->pluck('status')->all(),
        );
    }

    public function test_deleting_the_bill_takes_the_due_dates_and_the_payments(): void
    {
        $occurrenceId = $this->insertOccurrence($this->billId, '2026-04-10');
        $this->insertPayment($occurrenceId, '2026-04-10');

        DB::table('bills')->where('id', $this->billId)->delete();

        $this->assertSame(0, DB::table('bill_occurrences')->count());
        $this->assertSame(0, DB::table('payment_records')->count());
    }

    public function test_deleting_the_bill_takes_the_cadence_with_it(): void
    {
        $this->insertOccurrence($this->billId, '2026-04-10', $this->insertJobSchedule('2026-04-10'));

        DB::table('bills')->where('id', $this->billId)->delete();

        $this->assertSame(0, DB::table('trigger_configs')->count());
        $this->assertSame(0, DB::table('job_schedules')->count());
        $this->assertSame(0, DB::table('bill_occurrences')->count());
    }

    public function test_deleting_a_user_keeps_the_history_without_the_actor(): void
    {
        $user = User::factory()->create();
        $occurrenceId = $this->insertOccurrence($this->billId, '2026-04-10', status: 'paid');

        DB::table('payment_records')->insert([
            'id' => strtolower((string) Str::ulid()),
            'tenant_id' => $this->tenantId,
            'bill_occurrence_id' => $occurrenceId,
            'user_id' => $user->id,
            'amount' => 187.43,
            'method' => 'pix',
            'paid_at' => now(),
        ]);

        DB::table('users')->where('id', $user->id)->delete();

        $this->assertNull(DB::table('payment_records')->value('user_id'));
        $this->assertSame(1, DB::table('payment_records')->count());
    }

    // -------------------------------------------------------------- helpers

    private function insertBill(string $name, string $kind = Bill::KIND_FIXED): string
    {
        $id = strtolower((string) Str::ulid());

        DB::table('bills')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'name' => $name,
            'kind' => $kind,
            'currency' => 'BRL',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertOccurrence(
        string $billId,
        string $dueDate,
        ?string $scheduleId = null,
        string $status = BillOccurrence::STATUS_OPEN,
    ): string {
        $id = strtolower((string) Str::ulid());

        DB::table('bill_occurrences')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'bill_id' => $billId,
            'schedule_id' => $scheduleId,
            'due_date' => $dueDate,
            'amount' => 187.43,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertPayment(
        string $occurrenceId,
        string $dueDate,
        string $method = PaymentRecord::METHOD_PIX,
    ): string {
        $id = strtolower((string) Str::ulid());

        DB::table('payment_records')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'bill_occurrence_id' => $occurrenceId,
            'amount' => 187.43,
            'method' => $method,
            'paid_at' => now(),
        ]);

        return $id;
    }

    private function insertJobSchedule(string $scheduledFor): string
    {
        $configId = strtolower((string) Str::ulid());

        DB::table('trigger_configs')->insert([
            'id' => $configId,
            'tenant_id' => $this->tenantId,
            'subject_type' => 'bill',
            'bill_id' => $this->billId,
            'title' => 'Conta de luz',
            'type' => 'CALENDAR_MONTHLY',
            'day_of_month' => 10,
            'is_active' => true,
            'advance_notice_days' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $scheduleId = strtolower((string) Str::ulid());

        DB::table('job_schedules')->insert([
            'id' => $scheduleId,
            'tenant_id' => $this->tenantId,
            'trigger_config_id' => $configId,
            'scheduled_for' => $scheduledFor,
            'due_at' => now(),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $scheduleId;
    }

    private function assertRejected(callable $insert): void
    {
        try {
            $insert();
        } catch (QueryException) {
            return;
        }

        $this->fail('O banco aceitou uma linha que deveria ter sido recusada.');
    }
}
