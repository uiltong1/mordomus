<?php

namespace Mordomus\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Mordomus\Common\Eloquent\TenantGlobalScope;
use Mordomus\Identity\Auth\JwtGuard;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\User;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerJwtGuard();
        $this->registerCapabilityGate();
        $this->registerFactoryNames();
        $this->registerRateLimits();
        $this->registerTenantBindings();
    }

    /**
     * Binding de `Membership` fora do TenantGlobalScope.
     *
     * `SubstituteBindings` roda no grupo da rota, antes do middleware `tenant` —
     * ainda não há contexto de residência e o escopo devolveria `1 = 0`. A
     * fronteira fica em `MemberController`, que compara
     * `membership.tenant_id === tenant.id`.
     */
    private function registerTenantBindings(): void
    {
        Route::bind('membership', fn (string $value): Membership => Membership::query()
            ->withoutGlobalScope(TenantGlobalScope::class)
            ->findOrFail($value));
    }

    /** Guard stateless: driver `jwt` (config/auth.php). */
    private function registerJwtGuard(): void
    {
        Auth::extend('jwt', function ($app, $name, array $config) {
            $guard = new JwtGuard(JwtGuard::resolver(), $app['request']);

            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });
    }

    /**
     * ADR-007 — `$user->can('rules.edit')` resolve pela capability efetiva
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

    /** TECHSPEC §10.4 — rate limiting por IP e por credencial nas rotas de auth. */
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
