<?php

namespace Mordomus\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Mordomus\Common\Eloquent\TenantGlobalScope;
use Mordomus\Financial\Contracts\Repositories\BillOccurrenceRepositoryInterface;
use Mordomus\Financial\Contracts\Repositories\BillRepositoryInterface;
use Mordomus\Financial\Contracts\Repositories\PaymentRecordRepositoryInterface;
use Mordomus\Financial\Contracts\Repositories\SplitResultRepositoryInterface;
use Mordomus\Financial\Contracts\Repositories\SplitRuleRepositoryInterface;
use Mordomus\Financial\Contracts\Services\BillOccurrenceServiceInterface;
use Mordomus\Financial\Contracts\Services\BillScheduleConsumerServiceInterface;
use Mordomus\Financial\Contracts\Services\BillServiceInterface;
use Mordomus\Financial\Contracts\Services\BillSummaryServiceInterface;
use Mordomus\Financial\Contracts\Services\MoneyServiceInterface;
use Mordomus\Financial\Contracts\Services\SplitCalculatorInterface;
use Mordomus\Financial\Contracts\Services\SplitResultServiceInterface;
use Mordomus\Financial\Contracts\Services\SplitRuleServiceInterface;
use Mordomus\Financial\Listeners\BillScheduleListener;
use Mordomus\Financial\Repositories\BillOccurrenceRepository;
use Mordomus\Financial\Repositories\BillRepository;
use Mordomus\Financial\Repositories\PaymentRecordRepository;
use Mordomus\Financial\Repositories\SplitResultRepository;
use Mordomus\Financial\Repositories\SplitRuleRepository;
use Mordomus\Financial\Services\BillOccurrenceService;
use Mordomus\Financial\Services\BillScheduleConsumerService;
use Mordomus\Financial\Services\BillService;
use Mordomus\Financial\Services\BillSummaryService;
use Mordomus\Financial\Services\MoneyService;
use Mordomus\Financial\Services\SplitCalculator;
use Mordomus\Financial\Services\SplitResultService;
use Mordomus\Financial\Services\SplitRuleService;
use Mordomus\Identity\Auth\JwtGuard;
use Mordomus\Identity\Contracts\Repositories\InvitationRepositoryInterface;
use Mordomus\Identity\Contracts\Repositories\MembershipRepositoryInterface;
use Mordomus\Identity\Contracts\Repositories\PermissionRepositoryInterface;
use Mordomus\Identity\Contracts\Repositories\RefreshTokenRepositoryInterface;
use Mordomus\Identity\Contracts\Repositories\RoleRepositoryInterface;
use Mordomus\Identity\Contracts\Repositories\UserRepositoryInterface;
use Mordomus\Identity\Contracts\Services\AuthServiceInterface;
use Mordomus\Identity\Contracts\Services\CapabilityResolverServiceInterface;
use Mordomus\Identity\Contracts\Services\InvitationServiceInterface;
use Mordomus\Identity\Contracts\Services\JwtIssuerServiceInterface;
use Mordomus\Identity\Contracts\Services\JwtVerifierServiceInterface;
use Mordomus\Identity\Contracts\Services\MemberServiceInterface;
use Mordomus\Identity\Contracts\Services\ProfileServiceInterface;
use Mordomus\Identity\Contracts\Services\RefreshTokenServiceInterface;
use Mordomus\Identity\Contracts\Services\TenantProvisionerServiceInterface;
use Mordomus\Identity\Contracts\Services\TenantServiceInterface;
use Mordomus\Identity\Contracts\Services\TokenPackagerServiceInterface;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\User;
use Mordomus\Identity\Repositories\InvitationRepository;
use Mordomus\Identity\Repositories\MembershipRepository;
use Mordomus\Identity\Repositories\PermissionRepository;
use Mordomus\Identity\Repositories\RefreshTokenRepository;
use Mordomus\Identity\Repositories\RoleRepository;
use Mordomus\Identity\Repositories\UserRepository;
use Mordomus\Identity\Services\AuthService;
use Mordomus\Identity\Services\CapabilityResolverService;
use Mordomus\Identity\Services\InvitationService;
use Mordomus\Identity\Services\JwtIssuerService;
use Mordomus\Identity\Services\JwtVerifierService;
use Mordomus\Identity\Services\MemberService;
use Mordomus\Identity\Services\ProfileService;
use Mordomus\Identity\Services\RefreshTokenService;
use Mordomus\Identity\Services\TenantProvisionerService;
use Mordomus\Identity\Services\TenantService;
use Mordomus\Identity\Services\TokenPackagerService;
use Mordomus\Maintenance\Contracts\Repositories\AssetRepositoryInterface;
use Mordomus\Maintenance\Contracts\Repositories\RoomRepositoryInterface;
use Mordomus\Maintenance\Contracts\Services\AssetServiceInterface;
use Mordomus\Maintenance\Contracts\Services\RoomServiceInterface;
use Mordomus\Maintenance\Contracts\Services\TenantClockServiceInterface;
use Mordomus\Maintenance\Repositories\AssetRepository;
use Mordomus\Maintenance\Repositories\RoomRepository;
use Mordomus\Maintenance\Services\AssetService;
use Mordomus\Maintenance\Services\RoomService;
use Mordomus\Maintenance\Services\TenantClockService;
use Mordomus\Scheduling\Contracts\Repositories\JobScheduleRepositoryInterface;
use Mordomus\Scheduling\Contracts\Repositories\ScheduleEventRepositoryInterface;
use Mordomus\Scheduling\Contracts\Repositories\TriggerConfigRepositoryInterface;
use Mordomus\Scheduling\Contracts\Services\DueNoticeServiceInterface;
use Mordomus\Scheduling\Contracts\Services\EventPublisherServiceInterface;
use Mordomus\Scheduling\Contracts\Services\OccurrenceMaterializerServiceInterface;
use Mordomus\Scheduling\Contracts\Services\OccurrenceRecalculatorServiceInterface;
use Mordomus\Scheduling\Contracts\Services\OccurrenceServiceInterface;
use Mordomus\Scheduling\Contracts\Services\TenantCalendarServiceInterface;
use Mordomus\Scheduling\Contracts\Services\TriggerConfigServiceInterface;
use Mordomus\Scheduling\Contracts\Services\TriggerDateServiceInterface;
use Mordomus\Scheduling\Events\DomainEvent;
use Mordomus\Scheduling\Repositories\JobScheduleRepository;
use Mordomus\Scheduling\Repositories\ScheduleEventRepository;
use Mordomus\Scheduling\Repositories\TriggerConfigRepository;
use Mordomus\Scheduling\Services\DueNoticeService;
use Mordomus\Scheduling\Services\EventPublisherService;
use Mordomus\Scheduling\Services\OccurrenceMaterializerService;
use Mordomus\Scheduling\Services\OccurrenceRecalculatorService;
use Mordomus\Scheduling\Services\OccurrenceService;
use Mordomus\Scheduling\Services\TenantCalendarService;
use Mordomus\Scheduling\Services\TriggerConfigService;
use Mordomus\Scheduling\Services\TriggerDateService;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerMaintenanceBindings();
        $this->registerIdentityBindings();
        $this->registerSchedulingBindings();
        $this->registerFinancialBindings();
    }

    public function boot(): void
    {
        $this->registerJwtGuard();
        $this->registerCapabilityGate();
        $this->registerFactoryNames();
        $this->registerRateLimits();
        $this->registerTenantBindings();
        $this->registerFinancialListeners();
    }

    /**
     * Consumidor in-process do Scheduling (ADR-011).
     *
     * O envelope é publicado dentro do processo e a fila continua sendo a
     * entrega para quem só observa depois; aqui quem reage na mesma transação
     * é o Financial, que grava o `schedule_id` do vencimento logo depois de a
     * ocorrência do motor existir.
     */
    private function registerFinancialListeners(): void
    {
        Event::listen(DomainEvent::class, BillScheduleListener::class);
    }

    /**
     * Binding de `Membership` fora do TenantGlobalScope.
     *
     * `SubstituteBindings` roda no grupo da rota, antes do middleware `tenant` —
     * ainda não há contexto de residência e o escopo devolveria `1 = 0`. A
     * fronteira fica no FormRequest (tenant ativo) e no `MemberService`
     * (`membership.tenant_id === tenant.id`).
     */
    private function registerTenantBindings(): void
    {
        Route::bind('membership', fn (string $value): Membership => Membership::query()
            ->withoutGlobalScope(TenantGlobalScope::class)
            ->findOrFail($value));
    }

    private function registerMaintenanceBindings(): void
    {
        $this->app->bind(RoomRepositoryInterface::class, RoomRepository::class);
        $this->app->bind(AssetRepositoryInterface::class, AssetRepository::class);
        $this->app->bind(RoomServiceInterface::class, RoomService::class);
        $this->app->bind(AssetServiceInterface::class, AssetService::class);
        $this->app->bind(TenantClockServiceInterface::class, TenantClockService::class);
    }

    private function registerIdentityBindings(): void
    {
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(MembershipRepositoryInterface::class, MembershipRepository::class);
        $this->app->bind(InvitationRepositoryInterface::class, InvitationRepository::class);
        $this->app->bind(RoleRepositoryInterface::class, RoleRepository::class);
        $this->app->bind(PermissionRepositoryInterface::class, PermissionRepository::class);
        $this->app->bind(RefreshTokenRepositoryInterface::class, RefreshTokenRepository::class);

        $this->app->bind(AuthServiceInterface::class, AuthService::class);
        $this->app->bind(TenantServiceInterface::class, TenantService::class);
        $this->app->bind(MemberServiceInterface::class, MemberService::class);
        $this->app->bind(InvitationServiceInterface::class, InvitationService::class);
        $this->app->bind(ProfileServiceInterface::class, ProfileService::class);

        $this->app->bind(CapabilityResolverServiceInterface::class, CapabilityResolverService::class);
        $this->app->bind(JwtIssuerServiceInterface::class, JwtIssuerService::class);
        $this->app->bind(JwtVerifierServiceInterface::class, JwtVerifierService::class);
        $this->app->bind(RefreshTokenServiceInterface::class, RefreshTokenService::class);
        $this->app->bind(TokenPackagerServiceInterface::class, TokenPackagerService::class);
        $this->app->bind(TenantProvisionerServiceInterface::class, TenantProvisionerService::class);
    }

    private function registerSchedulingBindings(): void
    {
        $this->app->bind(TriggerConfigRepositoryInterface::class, TriggerConfigRepository::class);
        $this->app->bind(JobScheduleRepositoryInterface::class, JobScheduleRepository::class);
        $this->app->bind(ScheduleEventRepositoryInterface::class, ScheduleEventRepository::class);

        $this->app->bind(TriggerConfigServiceInterface::class, TriggerConfigService::class);
        $this->app->bind(OccurrenceServiceInterface::class, OccurrenceService::class);
        $this->app->bind(OccurrenceMaterializerServiceInterface::class, OccurrenceMaterializerService::class);
        $this->app->bind(OccurrenceRecalculatorServiceInterface::class, OccurrenceRecalculatorService::class);
        $this->app->bind(DueNoticeServiceInterface::class, DueNoticeService::class);
        $this->app->bind(EventPublisherServiceInterface::class, EventPublisherService::class);
        $this->app->bind(TriggerDateServiceInterface::class, TriggerDateService::class);
        $this->app->bind(TenantCalendarServiceInterface::class, TenantCalendarService::class);
    }

    private function registerFinancialBindings(): void
    {
        $this->app->bind(BillRepositoryInterface::class, BillRepository::class);
        $this->app->bind(BillOccurrenceRepositoryInterface::class, BillOccurrenceRepository::class);
        $this->app->bind(PaymentRecordRepositoryInterface::class, PaymentRecordRepository::class);
        $this->app->bind(SplitRuleRepositoryInterface::class, SplitRuleRepository::class);
        $this->app->bind(SplitResultRepositoryInterface::class, SplitResultRepository::class);

        $this->app->bind(BillServiceInterface::class, BillService::class);
        $this->app->bind(BillOccurrenceServiceInterface::class, BillOccurrenceService::class);
        $this->app->bind(BillSummaryServiceInterface::class, BillSummaryService::class);
        $this->app->bind(BillScheduleConsumerServiceInterface::class, BillScheduleConsumerService::class);
        $this->app->bind(MoneyServiceInterface::class, MoneyService::class);
        $this->app->bind(SplitCalculatorInterface::class, SplitCalculator::class);
        $this->app->bind(SplitRuleServiceInterface::class, SplitRuleService::class);
        $this->app->bind(SplitResultServiceInterface::class, SplitResultService::class);
    }

    /** Guard stateless: driver `jwt` (config/auth.php). */
    private function registerJwtGuard(): void
    {
        Auth::extend('jwt', function ($app, $name, array $config) {
            $guard = new JwtGuard(JwtGuard::resolver($app->make(JwtVerifierServiceInterface::class)), $app['request']);

            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });
    }

    /**
     * `$user->can('rules.edit')` resolve pela capability efetiva
     * (membership_grants → role_permissions) no tenant ativo da requisição.
     */
    private function registerCapabilityGate(): void
    {
        Gate::before(function (mixed $user, string $ability): ?bool {
            if ($user instanceof User && $user->hasCapability($ability)) {
                return true;
            }

            return null;
        });
    }

    /**
     * `Mordomus\<Módulo>\Models\X` → `Database\Factories\XFactory`.
     * O resolvedor padrão do Laravel usa a raiz do namespace (`Mordomus\`) e
     * perderia o segmento do módulo.
     */
    private function registerFactoryNames(): void
    {
        Factory::guessFactoryNamesUsing(function (string $modelName): string {
            $model = str_contains($modelName, '\\Models\\')
                ? Str::afterLast($modelName, '\\Models\\')
                : class_basename($modelName);

            return 'Database\\Factories\\'.$model.'Factory';
        });
    }

    /** Rate limiting por IP e por credencial nas rotas de auth. */
    private function registerRateLimits(): void
    {
        RateLimiter::for('auth', function (Request $request) {
            $identity = strtolower((string) ($request->input('email') ?: $request->ip()));

            return [
                Limit::perMinute(30)->by('ip:'.$request->ip()),
                Limit::perMinute(10)->by('identity:'.$identity),
            ];
        });
    }
}
