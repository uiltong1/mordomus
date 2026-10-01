<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mordomus\Financial\Events\EventName;
use Mordomus\Financial\Models\Bill;
use Mordomus\Financial\Models\BillOccurrence;
use Mordomus\Financial\Models\PaymentRecord;
use Mordomus\Financial\Models\SplitResult;
use Mordomus\Financial\Models\SplitRule;
use Mordomus\Financial\Services\MoneyService;
use Mordomus\Identity\Contracts\Services\CapabilityResolverServiceInterface;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Permission;
use Mordomus\Identity\Models\Role;
use Mordomus\Identity\Models\User;
use Mordomus\Scheduling\Jobs\PublishEvent;

/**
 * Divisão de cota-parte: a regra, a cota de cada morador, a baixa e o
 * recalcular (T5.2.4, T5.2.5).
 *
 * O relógio é congelado porque o que está em jogo aqui é a fronteira com o
 * Scheduling: o vencimento nasce na data que o motor calculou, e a cota é
 * sobre o valor que a casa registrou nele.
 *
 * Cada cenário é uma casa de três moradores — dono e dois membros — porque a
 * divisão só existe com mais de uma parte, e é a casa com membros que prova o
 * escopo de leitura.
 */
class SplitFlowTest extends FeatureTestCase
{
    private const NOW = '2026-04-01 12:00:00';

    private string $tenantId;

    /** @var array<string, string> */
    private array $headers;

    /** @var array<string, string> */
    private array $memberHeaders;

    private string $ownerId = '';

    private string $memberId = '';

    private string $otherId = '';

    private string $billId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $auth = $this->registerUser('divisao@mordomus.test', 'Ana', 'Casa Split');
        $this->tenantId = $auth['active_tenant'];
        $this->ownerId = (string) $auth['user']['id'];
        $this->headers = [
            'Authorization' => 'Bearer '.$auth['access_token'],
            'Accept' => 'application/json',
        ];

        // Dono e dois moradores: a divisão só existe com mais de uma parte, e a
        // casa com membros é o que prova o recorte de leitura.
        $this->memberId = $this->addResident('bruno@mordomus.test', 'Bruno');
        $this->otherId = $this->addResident('carla@mordomus.test', 'Carla');
        $this->memberHeaders = $this->authHeadersFor(User::query()->findOrFail($this->memberId), $this->tenantId);

        // O token é emitido antes do congelamento: o `firebase/php-jwt` valida
        // `iat`/`nbf` contra o relógio do sistema, e um token emitido no
        // "futuro" do relógio congelado seria recusado como inválido.
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::NOW, 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------ regra

    public function test_the_rule_of_the_house_is_saved_and_listed(): void
    {

        $this->saveHouseRule()
            ->assertOk()
            ->assertJsonPath('data.bill_id', null)
            ->assertJsonPath('data.mode', SplitRule::MODE_EQUAL)
            ->assertJsonPath('data.is_house_default', true)
            ->assertJsonCount(3, 'data.entries')
            ->assertJsonPath('data.entries.0.user_id', $this->ownerId)
            // `EQUAL` não usa peso: o campo fora do regime é proibido, e a
            // resposta volta com ele nulo para a tela não tentar ler.
            ->assertJsonPath('data.entries.0.weight', null)
            ->assertJsonPath('data.entries.0.percent', null);

        $this->withHeaders($this->headers)
            ->getJson('/api/v1/financial/split-rules')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_house_default', true);
    }

    public function test_saving_the_rule_of_the_same_bill_edits_it_instead_of_duplicating(): void
    {
        $billId = $this->createBill('Aluguel', '1800.00');

        $first = $this->saveRule([
            'bill_id' => $billId,
            'mode' => SplitRule::MODE_PERCENT,
            'entries' => [['user_id' => $this->ownerId, 'percent' => '50'], ['user_id' => $this->memberId, 'percent' => '50']],
        ])->assertOk()->json('data.id');

        $second = $this->saveRule([
            'bill_id' => $billId,
            'mode' => SplitRule::MODE_WEIGHTED,
            'entries' => [['user_id' => $this->ownerId, 'weight' => '1'], ['user_id' => $this->memberId, 'weight' => '1']],
        ])->assertOk()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, $this->ruleCount());
    }

    public function test_a_rule_of_a_bill_of_another_home_is_not_found(): void
    {
        // A conta nasce na outra casa, direto no banco: o token desta
        // residência não abriria a conta de lá para criá-la.
        $otherTenant = $this->registerUser('outra@mordomus.test', 'Casa', 'Outra Casa')['active_tenant'];
        $foreign = $this->withTenantContext($otherTenant, fn (): string => (string) Bill::create([
            'tenant_id' => $otherTenant,
            'name' => 'Conta de fora',
            'kind' => Bill::KIND_FIXED,
        ])->id);

        $this->saveRule(['bill_id' => $foreign, 'mode' => SplitRule::MODE_EQUAL, 'entries' => [['user_id' => $this->ownerId]]])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'bill_not_found');

        $this->assertSame(0, $this->ruleCount());
    }

    public function test_a_rule_needs_the_field_of_its_mode_and_refuses_the_others(): void
    {

        $this->saveRule([
            'mode' => SplitRule::MODE_PERCENT,
            'entries' => [['user_id' => $this->ownerId, 'percent' => '50'], ['user_id' => $this->memberId, 'percent' => '50']],
        ])->assertOk();

        // `PERCENT` exige percentual: peso no lugar dele é a regra que o
        // cálculo não consegue ler.
        $this->saveRule([
            'mode' => SplitRule::MODE_PERCENT,
            'entries' => [['user_id' => $this->ownerId, 'weight' => '1'], ['user_id' => $this->memberId, 'weight' => '1']],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['entries.0.percent']]]);

        // E o campo de outro regime é lixo que voltaria como puzzling na linha
        // do banco, então ele é recusado.
        $this->saveRule([
            'mode' => SplitRule::MODE_EQUAL,
            'entries' => [['user_id' => $this->ownerId, 'weight' => '1'], ['user_id' => $this->memberId]],
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['entries.0.weight']]]);
    }

    public function test_a_rule_only_accepts_residents_of_the_house(): void
    {

        $stranger = User::factory()->create()->id;

        $this->saveRule([
            'mode' => SplitRule::MODE_EQUAL,
            'entries' => [['user_id' => $this->ownerId], ['user_id' => $stranger]],
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['entries.1.user_id']]]);
    }

    public function test_a_resident_cannot_write_the_rule(): void
    {

        $this->withHeaders($this->memberHeaders)->putJson('/api/v1/financial/split-rules', [
            'mode' => SplitRule::MODE_EQUAL,
            'entries' => [['user_id' => $this->ownerId], ['user_id' => $this->memberId]],
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    // -------------------------------------------------------------- cota

    public function test_the_share_of_a_due_date_is_split_when_it_is_born(): void
    {
        $this->createBill('Conta de luz', '187.43');
        $this->saveHouseRule();

        $this->artisan('scheduling:materialize')->assertSuccessful();

        $this->split($this->occurrenceId('2026-04-10'))
            ->assertOk()
            ->assertJsonPath('data.mode', SplitRule::MODE_EQUAL)
            ->assertJsonPath('data.total', '187.43')
            ->assertJsonPath('data.scope', 'all')
            ->assertJsonCount(3, 'data.shares')
            ->assertJsonPath('data.shares.0.share_amount', '62.47')
            ->assertJsonPath('data.shares.1.share_amount', '62.47')
            ->assertJsonPath('data.shares.2.share_amount', '62.49')
            ->assertJsonPath('data.shares.0.settled', false);
    }

    /** Critério de aceite: a soma das cotas é o valor da conta. */
    public function test_the_shares_add_up_to_the_amount_of_the_due_date(): void
    {
        $this->createBill('Conta de luz', '187.43');
        $this->saveHouseRule();

        $this->artisan('scheduling:materialize')->assertSuccessful();

        foreach (['2026-04-10', '2026-05-10'] as $dueDate) {
            $shares = $this->split($this->occurrenceId($dueDate))->json('data.shares');

            $this->assertSame(
                '187.43',
                $this->money()->sum(array_column($shares, 'share_amount')),
                'A soma das cotas de '.$dueDate.' precisa ser o valor do lançamento (R5).',
            );
        }
    }

    public function test_the_bill_rule_wins_over_the_rule_of_the_house(): void
    {
        $billId = $this->createBill('Conta de luz', '100.00');

        $this->saveHouseRule();
        $this->saveRule([
            'bill_id' => $billId,
            'mode' => SplitRule::MODE_WEIGHTED,
            'entries' => [['user_id' => $this->ownerId, 'weight' => '3'], ['user_id' => $this->memberId, 'weight' => '1']],
        ])->assertOk();

        $this->artisan('scheduling:materialize')->assertSuccessful();

        $this->split($this->occurrenceId('2026-04-10'))
            ->assertOk()
            ->assertJsonPath('data.bill_id', $billId)
            ->assertJsonPath('data.is_house_default', false)
            ->assertJsonPath('data.mode', SplitRule::MODE_WEIGHTED)
            ->assertJsonCount(2, 'data.shares')
            ->assertJsonPath('data.shares.0.share_amount', '75.00')
            ->assertJsonPath('data.shares.1.share_amount', '25.00');
    }

    public function test_a_paused_rule_of_the_bill_falls_back_to_the_house(): void
    {
        $billId = $this->createBill('Conta de luz', '100.00');
        $this->saveHouseRule();

        $this->saveRule([
            'bill_id' => $billId,
            'mode' => SplitRule::MODE_CUSTOM,
            'entries' => [['user_id' => $this->ownerId, 'fixed_amount' => '100.00']],
            'is_active' => false,
        ])->assertOk();

        $this->artisan('scheduling:materialize')->assertSuccessful();

        // Regra pausada não divide: a conta volta ao padrão da casa, que
        // divide entre os três moradores dela.
        $this->split($this->occurrenceId('2026-04-10'))
            ->assertOk()
            ->assertJsonPath('data.is_house_default', true)
            ->assertJsonPath('data.mode', SplitRule::MODE_EQUAL)
            ->assertJsonCount(3, 'data.shares');
    }

    public function test_a_due_date_without_a_rule_is_not_found(): void
    {
        $this->createBill('Conta de luz', '187.43');

        $this->artisan('scheduling:materialize')->assertSuccessful();

        $this->split($this->occurrenceId('2026-04-10'))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'split_rule_not_found');
    }

    public function test_reading_the_split_of_a_due_date_of_another_home_is_not_found(): void
    {
        $this->createBill('Conta de luz', '187.43');
        $this->saveHouseRule();

        $foreign = $this->registerUser('lateral@mordomus.test', 'Lateral', 'Casa Lateral');
        $this->withTenantContext($foreign['active_tenant'], function (): void {
            BillOccurrence::query()->delete();
        });

        $this->artisan('scheduling:materialize')->assertSuccessful();
        $foreignId = $this->withTenantContext($foreign['active_tenant'], fn (): string => (string) BillOccurrence::query()
            ->where('due_date', '2026-04-10')
            ->value('id'));

        $this->split($foreignId)->assertStatus(404);
    }

    /** Critério de aceite: cada morador vê apenas a própria cota. */
    public function test_a_resident_sees_only_their_own_share(): void
    {
        $this->createBill('Conta de luz', '187.43');
        $this->saveHouseRule();

        $this->artisan('scheduling:materialize')->assertSuccessful();
        $occurrenceId = $this->occurrenceId('2026-04-10');

        $this->withHeaders($this->memberHeaders)
            ->getJson("/api/v1/financial/occurrences/{$occurrenceId}/split")
            ->assertOk()
            ->assertJsonPath('data.scope', 'own')
            ->assertJsonCount(1, 'data.shares')
            ->assertJsonPath('data.shares.0.user_id', $this->memberId)
            ->assertJsonPath('data.shares.0.share_amount', '62.47');
    }

    public function test_a_due_date_without_split_capability_is_refused(): void
    {
        $this->createBill('Conta de luz', '187.43');
        $this->saveHouseRule();

        $this->artisan('scheduling:materialize')->assertSuccessful();
        $occurrenceId = $this->occurrenceId('2026-04-10');

        $this->revokeSplitCapabilities($this->memberId);

        $this->withHeaders($this->memberHeaders)
            ->getJson("/api/v1/financial/occurrences/{$occurrenceId}/split")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    // ------------------------------------------------------ recalcular (AC)

    public function test_changing_the_rule_recalculates_the_open_due_dates(): void
    {
        $this->createBill('Conta de luz', '187.43');
        $this->saveHouseRule();

        $this->artisan('scheduling:materialize')->assertSuccessful();

        $this->assertSame(['62.47', '62.47', '62.49'], $this->shareAmounts($this->occurrenceId('2026-04-10')));

        // A casa mudou o combinado: a cota de quem está em aberto passa a ser
        // a do novo regime, sem ninguém precisar reabrir a tela.
        // 40/40/60 não fecha em 100 de propósito: a regra normaliza pela soma
        // dos percentuais, e 187,43 sai em 2/7, 2/7 e 3/7.
        $this->saveHouseRule(['mode' => SplitRule::MODE_PERCENT, 'entries' => [
            ['user_id' => $this->ownerId, 'percent' => '40'],
            ['user_id' => $this->memberId, 'percent' => '40'],
            ['user_id' => $this->otherId, 'percent' => '60'],
        ]])->assertOk();

        $this->assertSame(['53.55', '53.55', '80.33'], $this->shareAmounts($this->occurrenceId('2026-04-10')));
        $this->assertSame(['53.55', '53.55', '80.33'], $this->shareAmounts($this->occurrenceId('2026-05-10')));
    }

    public function test_recalculating_keeps_a_paid_due_date_as_it_was(): void
    {
        $this->createBill('Conta de luz', '187.43');
        $this->saveHouseRule();

        $this->artisan('scheduling:materialize')->assertSuccessful();
        $occurrenceId = $this->occurrenceId('2026-04-10');
        $this->pay($occurrenceId)->assertOk();

        $this->saveHouseRule(['mode' => SplitRule::MODE_PERCENT, 'entries' => [
            ['user_id' => $this->ownerId, 'percent' => '40'],
            ['user_id' => $this->memberId, 'percent' => '40'],
            ['user_id' => $this->otherId, 'percent' => '60'],
        ]])->assertOk();

        // Conta quitada tem o valor congelado no que foi pago: reescrever a
        // divisão dela trocaria o histórico de quem pagou o quê.
        $this->assertSame(['62.47', '62.47', '62.49'], $this->shareAmounts($occurrenceId));
        $this->assertSame(['53.55', '53.55', '80.33'], $this->shareAmounts($this->occurrenceId('2026-05-10')));
    }

    public function test_recalculating_drops_the_share_of_a_resident_that_left_the_rule(): void
    {
        $this->createBill('Conta de luz', '187.43');
        $this->saveHouseRule();

        $this->artisan('scheduling:materialize')->assertSuccessful();

        $this->saveHouseRule(['entries' => [['user_id' => $this->ownerId]]])->assertOk();

        $occurrenceId = $this->occurrenceId('2026-04-10');

        $this->assertSame(['187.43'], $this->shareAmounts($occurrenceId));
        $this->assertSame(1, $this->resultCount($occurrenceId));
    }

    public function test_pausing_the_rule_removes_the_shares_it_produced(): void
    {
        $billId = $this->createBill('Conta de luz', '187.43');
        $this->saveRule([
            'bill_id' => $billId,
            'mode' => SplitRule::MODE_CUSTOM,
            'entries' => [['user_id' => $this->ownerId, 'fixed_amount' => '100.00']],
        ])->assertOk();

        $this->artisan('scheduling:materialize')->assertSuccessful();
        $occurrenceId = $this->occurrenceId('2026-04-10');
        $this->assertSame(1, $this->resultCount($occurrenceId));

        // Sem regra padrão e com a da conta pausada, a conta deixa de ser
        // dividida — e cota zerada não é o que a casa veria.
        $this->saveRule([
            'bill_id' => $billId,
            'mode' => SplitRule::MODE_CUSTOM,
            'entries' => [['user_id' => $this->ownerId, 'fixed_amount' => '100.00']],
            'is_active' => false,
        ])->assertOk();

        $this->assertSame(0, $this->resultCount($occurrenceId));
        $this->split($occurrenceId)->assertStatus(404);
    }

    // ------------------------------------------------- baixa e evento (AC)

    public function test_the_share_is_settled_and_the_event_carries_the_shares(): void
    {
        Queue::fake([PublishEvent::class]);

        $this->createBill('Conta de luz', '187.43');
        $this->saveHouseRule();

        $this->artisan('scheduling:materialize')->assertSuccessful();
        $occurrenceId = $this->occurrenceId('2026-04-10');

        $this->split($occurrenceId)
            ->assertOk()
            ->assertJsonPath('data.shares.0.settled', false)
            ->assertJsonPath('data.shares.1.settled', false);

        $this->settle($occurrenceId, ['user_id' => $this->memberId])
            ->assertOk()
            ->assertJsonPath('data.shares.1.settled', true)
            ->assertJsonPath('data.shares.1.settled_at', '2026-04-01T09:00:00-03:00')
            ->assertJsonPath('data.shares.0.settled', false);

        // Um aviso por vencimento, e só quando o número muda: a baixa não
        // publica, e o motor materializou abril e maio numa passada só.
        $events = Queue::pushed(PublishEvent::class)
            ->map(fn (PublishEvent $job): array => $job->envelope)
            ->filter(fn (array $envelope): bool => $envelope['event'] === EventName::EXPENSE_SPLIT_COMPUTED)
            ->filter(fn (array $envelope): bool => $envelope['payload']['bill_occurrence_id'] === $occurrenceId)
            ->values();

        $this->assertCount(1, $events);

        $payload = $events[0]['payload'];

        $this->assertSame($occurrenceId, $payload['bill_occurrence_id']);
        $this->assertSame('187.43', $payload['amount']);
        $this->assertSame(SplitRule::MODE_EQUAL, $payload['mode']);
        $this->assertSame(['62.47', '62.47', '62.49'], array_column($payload['shares'], 'share_amount'));
        $this->assertNotEmpty($payload['dedupe_key']);
    }

    public function test_settling_twice_keeps_the_instant_of_the_first_low(): void
    {
        $this->createBill('Conta de luz', '187.43');
        $this->saveHouseRule();

        $this->artisan('scheduling:materialize')->assertSuccessful();
        $occurrenceId = $this->occurrenceId('2026-04-10');

        $this->settle($occurrenceId, ['user_id' => $this->memberId])->assertOk();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-02 12:00:00', 'UTC'));

        $this->settle($occurrenceId, ['user_id' => $this->memberId])
            ->assertOk()
            ->assertJsonPath('data.shares.1.settled_at', '2026-04-01T09:00:00-03:00');
    }

    public function test_the_low_can_be_undone(): void
    {
        $this->createBill('Conta de luz', '187.43');
        $this->saveHouseRule();

        $this->artisan('scheduling:materialize')->assertSuccessful();
        $occurrenceId = $this->occurrenceId('2026-04-10');

        $this->settle($occurrenceId, ['user_id' => $this->memberId])->assertOk();
        $this->settle($occurrenceId, ['user_id' => $this->memberId, 'settled' => false])
            ->assertOk()
            ->assertJsonPath('data.shares.1.settled', false)
            ->assertJsonPath('data.shares.1.settled_at', null);
    }

    public function test_settling_a_resident_without_a_share_is_not_found(): void
    {
        $this->createBill('Conta de luz', '187.43');
        $this->saveHouseRule(['entries' => [['user_id' => $this->ownerId]]]);

        $this->artisan('scheduling:materialize')->assertSuccessful();

        $this->settle($this->occurrenceId('2026-04-10'), ['user_id' => $this->memberId])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'split_result_not_found');
    }

    public function test_a_resident_cannot_settle(): void
    {
        $this->createBill('Conta de luz', '187.43');
        $this->saveHouseRule();

        $this->artisan('scheduling:materialize')->assertSuccessful();
        $occurrenceId = $this->occurrenceId('2026-04-10');

        $this->withHeaders($this->memberHeaders)
            ->patchJson("/api/v1/financial/occurrences/{$occurrenceId}/split", ['user_id' => $this->memberId])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    /**
     * A cota é do valor que a casa quitou: em conta variável o valor real só
     * aparece na baixa, e a divisão feita sobre o valor previsto seria a
     * divisão de um valor que ninguém pagou.
     */
    public function test_paying_with_the_real_amount_redivides_the_share(): void
    {
        $billId = $this->createBill('Gás', null, Bill::KIND_VARIABLE);
        $this->saveHouseRule();

        $this->withHeaders($this->headers)->postJson('/api/v1/financial/occurrences', [
            'bill_id' => $billId,
            'due_date' => '2026-04-18',
            'amount' => '0.00',
        ])->assertCreated();

        $occurrenceId = $this->occurrenceId('2026-04-18');
        $this->assertSame(['0.00', '0.00', '0.00'], $this->shareAmounts($occurrenceId));

        $this->pay($occurrenceId, ['method' => PaymentRecord::METHOD_PIX, 'amount' => '213.77'])->assertOk();

        $this->assertSame(['71.25', '71.25', '71.27'], $this->shareAmounts($occurrenceId));
    }

    /**
     * A baixa vale para o número com que o morador concordou: cota que muda de
     * valor volta a ficar em aberto, e a que não muda continua quitada.
     */
    public function test_a_share_that_changes_value_goes_back_to_open(): void
    {
        $this->createBill('Conta de luz', '100.00');
        $this->saveHouseRule(['entries' => [
            ['user_id' => $this->ownerId],
            ['user_id' => $this->memberId],
        ]]);

        $this->artisan('scheduling:materialize')->assertSuccessful();
        $occurrenceId = $this->occurrenceId('2026-04-10');

        $this->settle($occurrenceId, ['user_id' => $this->ownerId])->assertOk();
        $this->settle($occurrenceId, ['user_id' => $this->memberId])->assertOk();

        // Regra salva de novo sem mudar o número: o que foi quitado continua
        // valendo, porque a baixa era para estes valores.
        $this->saveHouseRule(['entries' => [
            ['user_id' => $this->ownerId],
            ['user_id' => $this->memberId],
        ]])->assertOk();

        $this->split($occurrenceId)
            ->assertOk()
            ->assertJsonPath('data.shares.0.share_amount', '50.00')
            ->assertJsonPath('data.shares.0.settled', true)
            ->assertJsonPath('data.shares.1.settled', true);

        $this->saveHouseRule(['mode' => SplitRule::MODE_WEIGHTED, 'entries' => [
            ['user_id' => $this->ownerId, 'weight' => '3'],
            ['user_id' => $this->memberId, 'weight' => '1'],
        ]])->assertOk();

        $this->split($occurrenceId)
            ->assertOk()
            ->assertJsonPath('data.shares.0.share_amount', '75.00')
            ->assertJsonPath('data.shares.0.settled', false)
            ->assertJsonPath('data.shares.0.settled_at', null)
            ->assertJsonPath('data.shares.1.settled', false);
    }

    public function test_a_rule_that_cannot_divide_is_refused_with_the_number(): void
    {
        $this->createBill('Conta de luz', '100.00');

        // Cota fechada que passa do total: a última ficaria negativa, e dívida
        // invertida não é resposta para o morador.
        $this->saveRule([
            'bill_id' => $this->billId,
            'mode' => SplitRule::MODE_CUSTOM,
            'entries' => [['user_id' => $this->ownerId, 'fixed_amount' => '120.00'], ['user_id' => $this->memberId, 'fixed_amount' => '50.00']],
        ])->assertOk();

        $this->artisan('scheduling:materialize')->assertSuccessful();

        $this->split($this->occurrenceId('2026-04-10'))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'split_not_computable')
            ->assertJsonPath('error.details.reason', 'exceeds_total')
            ->assertJsonPath('error.details.total', '100.00');
    }

    // -------------------------------------------------------------- helpers

    private function createBill(string $name, ?string $amount, string $kind = Bill::KIND_FIXED): string
    {
        $this->billId = (string) $this->withHeaders($this->headers)
            ->postJson('/api/v1/financial/bills', array_filter([
                'name' => $name,
                'kind' => $kind,
                'amount' => $amount,
                'due_day' => 10,
            ], fn (mixed $value): bool => $value !== null))
            ->assertCreated()
            ->json('data.id');

        return $this->billId;
    }

    /** @param array<string, mixed> $payload */
    private function saveHouseRule(array $payload = []): TestResponse
    {
        return $this->saveRule($payload + [
            'mode' => SplitRule::MODE_EQUAL,
            'entries' => [
                ['user_id' => $this->ownerId],
                ['user_id' => $this->memberId],
                ['user_id' => $this->otherId],
            ],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function saveRule(array $payload): TestResponse
    {
        return $this->withHeaders($this->headers)->putJson('/api/v1/financial/split-rules', $payload);
    }

    private function split(string $occurrenceId): TestResponse
    {
        return $this->withHeaders($this->headers)
            ->getJson("/api/v1/financial/occurrences/{$occurrenceId}/split");
    }

    /** @param array<string, mixed> $payload */
    private function settle(string $occurrenceId, array $payload): TestResponse
    {
        return $this->withHeaders($this->headers)
            ->patchJson("/api/v1/financial/occurrences/{$occurrenceId}/split", $payload);
    }

    /** @param array<string, mixed> $payload */
    private function pay(string $occurrenceId, array $payload = []): TestResponse
    {
        return $this->withHeaders($this->headers)->postJson(
            '/api/v1/financial/occurrences/'.$occurrenceId.'/paid',
            $payload + ['method' => PaymentRecord::METHOD_PIX],
        );
    }

    private function addResident(string $email, string $name): string
    {
        $user = User::factory()->create(['email' => $email, 'name' => $name]);

        Membership::create([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role_id' => Role::systemByKey(Role::MEMBER)->id,
            'status' => Membership::STATUS_ACTIVE,
        ]);

        return (string) $user->id;
    }

    /**
     * Tira as duas capabilities de split do morador.
     *
     * Grant negativo no pivot, e não sync vazio: pivot vazio é "sem
     * grant", e quem resolve a capability cai no papel — que continua dando
     * as duas.
     */
    private function revokeSplitCapabilities(string $userId): void
    {
        $membership = $this->withTenantContext($this->tenantId, fn () => Membership::query()
            ->where('user_id', $userId)
            ->where('tenant_id', $this->tenantId)
            ->firstOrFail());

        $permissions = $this->withTenantContext(
            $this->tenantId,
            fn (): Collection => Permission::query()
                ->whereIn('key', ['splits.manage', 'splits.view_own'])
                ->get(),
        );

        $membership->permissionGrants()->sync(
            $permissions->mapWithKeys(fn (Permission $permission): array => [$permission->id => ['granted' => false]])->all(),
        );

        app(CapabilityResolverServiceInterface::class)->forget($membership);
    }

    /** @return list<string> */
    private function shareAmounts(string $occurrenceId): array
    {
        $shares = $this->split($occurrenceId)->assertOk()->json('data.shares');

        return array_column($shares, 'share_amount');
    }

    private function resultCount(string $occurrenceId): int
    {
        return $this->withTenantContext($this->tenantId, fn (): int => SplitResult::query()
            ->where('bill_occurrence_id', $occurrenceId)
            ->count());
    }

    private function ruleCount(): int
    {
        return $this->withTenantContext($this->tenantId, fn (): int => SplitRule::query()->count());
    }

    private function occurrenceId(string $dueDate): string
    {
        return $this->withTenantContext(
            $this->tenantId,
            fn (): string => (string) BillOccurrence::query()->where('due_date', $dueDate)->value('id'),
        );
    }

    private function money(): MoneyService
    {
        return new MoneyService;
    }
}
