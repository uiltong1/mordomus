<?php

namespace Tests\Unit;

use Carbon\CarbonImmutable;
use Mordomus\Notification\Contracts\Repositories\NotificationPreferenceRepositoryInterface;
use Mordomus\Notification\Models\NotificationPreference;
use Mordomus\Notification\Services\QuietWindowService;
use Tests\TestCase;

/**
 * Quiet hours, digest e horário preferido (T6.1.3).
 *
 * O critério de aceite da tarefa é "usuário não recebe push entre `quiet_start`
 * e `quiet_end`", e o que se prova aqui é a outra metade dele: dentro da
 * janela o aviso é **adiado**, nunca descartado. A diferença entre as duas
 * coisas é o que separa um morador que acorda sabendo do que perdeu de um
 * morador que nunca soube.
 *
 * Sem banco: o serviço só precisa das três camadas da preferência, e elas
 * entram por um contrato à parte.
 */
class QuietWindowServiceTest extends TestCase
{
    private const SAO_PAULO = 'America/Sao_Paulo';

    private const NIGHT_START = '22:00';

    private const NIGHT_END = '07:00';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'notification.defaults.quiet_start' => self::NIGHT_START,
            'notification.defaults.quiet_end' => self::NIGHT_END,
            'notification.defaults.digest' => NotificationPreference::DIGEST_INSTANT,
            'notification.defaults.preferred_hour' => '09:00',
        ]);
    }

    // ------------------------------------------------------- a janela de silêncio

    public function test_inside_the_window_is_quiet(): void
    {
        $service = $this->service();
        $preference = $this->preference();

        $this->assertTrue($service->isQuietAt($preference, $this->at('23:30'), self::SAO_PAULO));
        $this->assertTrue($service->isQuietAt($preference, $this->at('03:00'), self::SAO_PAULO));
    }

    public function test_outside_the_window_is_not_quiet(): void
    {
        $service = $this->service();
        $preference = $this->preference();

        $this->assertFalse($service->isQuietAt($preference, $this->at('12:00'), self::SAO_PAULO));
        $this->assertFalse($service->isQuietAt($preference, $this->at('21:59'), self::SAO_PAULO));
        $this->assertFalse($service->isQuietAt($preference, $this->at('07:00'), self::SAO_PAULO));
    }

    /**
     * A janela atravessa a meia-noite: 22:00–07:00 é uma janela só, e é assim
     * que 03:00 continua sendo silêncio mesmo com `quiet_start` às 22:00.
     */
    public function test_the_window_wraps_midnight(): void
    {
        $preference = $this->preference();

        $this->assertTrue($preference->quietWindowWrapsMidnight());
        $this->assertSame(1320, $preference->quietStartMinutes());
        $this->assertSame(420, $preference->quietEndMinutes());
    }

    public function test_a_window_inside_the_day_does_not_wrap(): void
    {
        $preference = $this->preference(quietStart: '13:00', quietEnd: '15:00');

        $this->assertFalse($preference->quietWindowWrapsMidnight());
        $this->assertTrue($this->service()->isQuietAt($preference, $this->at('14:00'), self::SAO_PAULO));
        $this->assertFalse($this->service()->isQuietAt($preference, $this->at('15:00'), self::SAO_PAULO));
    }

    /** Início e fim iguais é ausência de janela, e não silêncio de 24 h. */
    public function test_equal_sides_is_not_a_window(): void
    {
        $preference = $this->preference(quietStart: '22:00', quietEnd: '22:00');

        $this->assertFalse($this->service()->isQuietAt($preference, $this->at('23:00'), self::SAO_PAULO));
    }

    public function test_without_a_window_nothing_is_quiet(): void
    {
        $preference = new NotificationPreference(['digest' => NotificationPreference::DIGEST_INSTANT]);

        $this->assertFalse($this->service()->isQuietAt($preference, $this->at('03:00'), self::SAO_PAULO));
    }

    // ------------------------------------------------------------ o adiamento

    public function test_outside_the_window_it_goes_out_now(): void
    {
        $delivered = $this->service()->deliverAt($this->preference(), $this->at('12:00'), self::SAO_PAULO);

        $this->assertSame('2026-04-01T12:00:00-03:00', $delivered->toIso8601String());
    }

    public function test_late_at_night_it_waits_for_the_morning(): void
    {
        $delivered = $this->service()->deliverAt($this->preference(), $this->at('23:30'), self::SAO_PAULO);

        $this->assertSame('2026-04-02T07:00:00-03:00', $delivered->toIso8601String());
    }

    public function test_early_morning_it_waits_for_the_same_morning(): void
    {
        $delivered = $this->service()->deliverAt($this->preference(), $this->at('03:00'), self::SAO_PAULO);

        $this->assertSame('2026-04-01T07:00:00-03:00', $delivered->toIso8601String());
    }

    /** Janela dentro do dia não vira a meia-noite, e o fim é o do mesmo dia. */
    public function test_a_daytime_window_ends_the_same_day(): void
    {
        $preference = $this->preference(quietStart: '13:00', quietEnd: '15:00');

        $delivered = $this->service()->deliverAt($preference, $this->at('14:00'), self::SAO_PAULO);

        $this->assertSame('2026-04-01T15:00:00-03:00', $delivered->toIso8601String());
    }

    // -------------------------------------------------------- o digest diário

    public function test_the_daily_digest_waits_for_the_preferred_hour(): void
    {
        $preference = $this->preference(digest: NotificationPreference::DIGEST_DAILY, preferredHour: '09:00');

        $delivered = $this->service()->deliverAt($preference, $this->at('20:00'), self::SAO_PAULO);

        $this->assertSame('2026-04-02T09:00:00-03:00', $delivered->toIso8601String());
    }

    /** Slot de hoje ainda pela frente: sai hoje, e não amanhã. */
    public function test_the_daily_digest_goes_out_today_when_the_hour_is_ahead(): void
    {
        $preference = $this->preference(digest: NotificationPreference::DIGEST_DAILY, preferredHour: '09:00');

        $delivered = $this->service()->deliverAt($preference, $this->at('06:00'), self::SAO_PAULO);

        $this->assertSame('2026-04-01T09:00:00-03:00', $delivered->toIso8601String());
    }

    /**
     * Horário preferido dentro da janela de silêncio é desobedeça-la: a casa
     * que pediu silêncio até as 07:00 não pode receber e-mail às 06:00 porque o
     * slot padrão é esse.
     */
    public function test_the_daily_digest_respects_the_quiet_window(): void
    {
        $preference = $this->preference(
            digest: NotificationPreference::DIGEST_DAILY,
            preferredHour: '06:00',
        );

        $delivered = $this->service()->deliverAt($preference, $this->at('04:00'), self::SAO_PAULO);

        $this->assertSame('2026-04-01T07:00:00-03:00', $delivered->toIso8601String());
    }

    // ----------------------------------------------------------- a precedência

    public function test_the_own_preference_wins_over_the_house(): void
    {
        $house = $this->preference(quietStart: '20:00', quietEnd: '21:00', digest: NotificationPreference::DIGEST_DAILY);
        // Digest nulo é "não mexi nesse campo", e é o que separa a precedência
        // de campo da precedência de linha.
        $own = new NotificationPreference(['quiet_start' => '13:00', 'quiet_end' => '14:00']);

        $effective = $this->service($own, $house)->effective('tenant', 'user');

        $this->assertSame('13:00', $effective->quiet_start?->format('H:i'));
        $this->assertSame('14:00', $effective->quiet_end?->format('H:i'));
        $this->assertSame(NotificationPreference::DIGEST_DAILY, $effective->digest);
    }

    public function test_the_house_preference_wins_over_the_environment(): void
    {
        $house = $this->preference(quietStart: '20:00', quietEnd: '21:00');

        $effective = $this->service(null, $house)->effective('tenant', 'user');

        $this->assertSame('20:00', $effective->quiet_start?->format('H:i'));
        $this->assertSame('09:00', $effective->preferred_hour?->format('H:i'));
    }

    public function test_the_environment_is_the_last_resort(): void
    {
        $effective = $this->service()->effective('tenant', 'user');

        $this->assertSame('22:00', $effective->quiet_start?->format('H:i'));
        $this->assertSame('07:00', $effective->quiet_end?->format('H:i'));
        $this->assertSame(NotificationPreference::DIGEST_INSTANT, $effective->digest);
        $this->assertSame('09:00', $effective->preferred_hour?->format('H:i'));
    }

    /** A precedência resolvida não é gravada: ela é a resposta, não o cadastro. */
    public function test_the_resolved_preference_is_not_persisted(): void
    {
        $effective = $this->service()->effective('tenant', 'user');

        $this->assertFalse($effective->exists);
        $this->assertNull($effective->tenant_id);
    }

    // -------------------------------------------------------------- helpers

    private function service(
        ?NotificationPreference $own = null,
        ?NotificationPreference $house = null,
    ): QuietWindowService {
        return new QuietWindowService($this->repository($own, $house));
    }

    private function repository(?NotificationPreference $own, ?NotificationPreference $house): NotificationPreferenceRepositoryInterface
    {
        $repository = $this->createMock(NotificationPreferenceRepositoryInterface::class);
        $repository->method('findForUser')->willReturn($own);
        $repository->method('findForHouse')->willReturn($house);

        return $repository;
    }

    private function preference(
        string $quietStart = self::NIGHT_START,
        string $quietEnd = self::NIGHT_END,
        string $digest = NotificationPreference::DIGEST_INSTANT,
        string $preferredHour = '09:00',
    ): NotificationPreference {
        return new NotificationPreference([
            'quiet_start' => $quietStart,
            'quiet_end' => $quietEnd,
            'digest' => $digest,
            'preferred_hour' => $preferredHour,
        ]);
    }

    private function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-04-01 '.$time.':00', self::SAO_PAULO)->utc();
    }
}
