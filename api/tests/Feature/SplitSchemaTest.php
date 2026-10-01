<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mordomus\Financial\Models\BillOccurrence;
use Mordomus\Financial\Models\SplitRule;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Identity\Models\User;

/**
 * Esquema da divisão de cota-parte.
 *
 * O que se verifica são as garantias que o banco precisa dar sozinho: uma
 * regra por conta (e uma só regra padrão da casa), um morador por entrada, uma
 * cota por morador e por vencimento — que é o que torna o recálculo
 * idempotente — e o destino das linhas filhas quando o pai é removido.
 *
 * As inserções são feitas por `DB::table` porque este teste é sobre o esquema,
 * não sobre a camada de acesso.
 */
class SplitSchemaTest extends FeatureTestCase
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
        $this->assertTrue(Schema::hasColumns('split_rules', [
            'id', 'tenant_id', 'bill_id', 'mode', 'is_active', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('split_entries', [
            'id', 'split_rule_id', 'user_id', 'weight', 'percent', 'fixed_amount', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('split_results', [
            'id', 'tenant_id', 'bill_occurrence_id', 'user_id', 'share_amount', 'settled',
            'settled_at', 'created_at', 'updated_at',
        ]));
    }

    /**
     * Uma regra por conta e uma regra padrão da casa.
     *
     * O `NULL` de `bill_id` é o que torna isso necessário: ele não colide em
     * índice único, e duas regras padrão valendo ao mesmo tempo fariam a mesma
     * conta ser dividida de dois jeitos sem o banco reclamar.
     */
    public function test_a_home_has_at_most_one_rule_per_bill_and_one_default(): void
    {
        $this->insertRule(null);
        $this->insertRule($this->billId);

        $this->assertRejected(fn () => $this->insertRule(null));
        $this->assertRejected(fn () => $this->insertRule($this->billId));

        // Conta diferente é regra diferente: o índice é por conta, e não por
        // residência.
        $this->insertRule($this->insertBill('Aluguel'));

        $this->assertSame(3, DB::table('split_rules')->count());
    }

    public function test_the_mode_is_constrained_by_the_database(): void
    {
        $this->assertRejected(fn () => $this->insertRule(null, mode: 'LOTERIA'));

        $this->insertRule(null, mode: SplitRule::MODE_PERCENT);
        $this->insertRule($this->billId, mode: SplitRule::MODE_CUSTOM);

        $this->assertSame(
            [SplitRule::MODE_PERCENT, SplitRule::MODE_CUSTOM],
            DB::table('split_rules')->orderByRaw('(bill_id is null) desc')->pluck('mode')->all(),
        );
    }

    public function test_a_resident_enters_the_rule_only_once(): void
    {
        $userId = User::factory()->create()->id;
        $ruleId = $this->insertRule(null);

        $this->insertEntry($ruleId, $userId);

        $this->assertRejected(fn () => $this->insertEntry($ruleId, $userId));

        $this->assertSame(1, DB::table('split_entries')->count());
    }

    public function test_entry_and_rule_refer_to_rows_that_exist(): void
    {
        $ruleId = $this->insertRule(null);
        $userId = User::factory()->create()->id;

        $this->assertRejected(fn () => $this->insertEntry((string) Str::ulid(), $userId));
        $this->assertRejected(fn () => $this->insertEntry($ruleId, (string) Str::ulid()));

        $this->assertSame(0, DB::table('split_entries')->count());
    }

    /**
     * A cota é única por morador e por vencimento: é o que faz o recálculo
     * atualizar a linha em vez de abrir uma segunda cota para o mesmo morador.
     */
    public function test_a_resident_has_at_most_one_share_per_due_date(): void
    {
        $userId = User::factory()->create()->id;
        $occurrenceId = $this->insertOccurrence();

        $this->insertResult($occurrenceId, $userId);

        $this->assertRejected(fn () => $this->insertResult($occurrenceId, $userId));

        // A mesma cota em outro vencimento é outra linha: cada conta é
        // dividida por conta.
        $this->insertResult($this->insertOccurrence('2026-05-10'), $userId);

        $this->assertSame(2, DB::table('split_results')->count());
    }

    public function test_share_and_rule_refer_to_rows_that_exist(): void
    {
        $userId = User::factory()->create()->id;
        $occurrenceId = $this->insertOccurrence();

        $this->assertRejected(fn () => $this->insertResult((string) Str::ulid(), $userId));
        $this->assertRejected(fn () => $this->insertResult($occurrenceId, (string) Str::ulid()));

        $this->assertSame(0, DB::table('split_results')->count());
    }

    /** Apagar a conta leva a regra, os participantes e as cotas dela. */
    public function test_deleting_the_bill_takes_the_rule_with_it(): void
    {
        $ruleId = $this->insertRule($this->billId);
        $userId = User::factory()->create()->id;
        $this->insertEntry($ruleId, $userId);
        $this->insertResult($this->insertOccurrence(), $userId);

        DB::table('bills')->where('id', $this->billId)->delete();

        $this->assertSame(0, DB::table('split_rules')->count());
        $this->assertSame(0, DB::table('split_entries')->count());
        $this->assertSame(0, DB::table('bill_occurrences')->count());
        $this->assertSame(0, DB::table('split_results')->count());
    }

    /**
     * A cota já dividida é histórico: perder o morador deixa o valor no lugar,
     * e o que ele deve continua legível.
     */
    public function test_deleting_a_user_keeps_the_share_without_the_actor(): void
    {
        $userId = User::factory()->create()->id;
        $occurrenceId = $this->insertOccurrence();
        $this->insertResult($occurrenceId, $userId, settled: true);

        DB::table('users')->where('id', $userId)->delete();

        $this->assertNull(DB::table('split_results')->value('user_id'));
        $this->assertSame('62.48', (string) DB::table('split_results')->value('share_amount'));
        $this->assertTrue((bool) DB::table('split_results')->value('settled'));
    }

    /** Participante que sai da residência sai da regra — e só dela. */
    public function test_deleting_a_user_takes_the_entry_out_of_the_rule(): void
    {
        $userId = User::factory()->create()->id;
        $ruleId = $this->insertRule(null);
        $this->insertEntry($ruleId, $userId);

        DB::table('users')->where('id', $userId)->delete();

        $this->assertSame(1, DB::table('split_rules')->count());
        $this->assertSame(0, DB::table('split_entries')->count());
    }

    // -------------------------------------------------------------- helpers

    private function insertBill(string $name): string
    {
        $id = strtolower((string) Str::ulid());

        DB::table('bills')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'name' => $name,
            'kind' => 'fixed',
            'currency' => 'BRL',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertRule(?string $billId, string $mode = SplitRule::MODE_EQUAL): string
    {
        $id = strtolower((string) Str::ulid());

        DB::table('split_rules')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'bill_id' => $billId,
            'mode' => $mode,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertEntry(string $ruleId, string $userId): void
    {
        DB::table('split_entries')->insert([
            'id' => strtolower((string) Str::ulid()),
            'split_rule_id' => $ruleId,
            'user_id' => $userId,
            'weight' => null,
            'percent' => null,
            'fixed_amount' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertOccurrence(string $dueDate = '2026-04-10'): string
    {
        $id = strtolower((string) Str::ulid());

        DB::table('bill_occurrences')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'bill_id' => $this->billId,
            'schedule_id' => null,
            'due_date' => $dueDate,
            'amount' => 187.43,
            'status' => BillOccurrence::STATUS_OPEN,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertResult(string $occurrenceId, string $userId, bool $settled = false): void
    {
        DB::table('split_results')->insert([
            'id' => strtolower((string) Str::ulid()),
            'tenant_id' => $this->tenantId,
            'bill_occurrence_id' => $occurrenceId,
            'user_id' => $userId,
            'share_amount' => 62.48,
            'settled' => $settled,
            'settled_at' => $settled ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
