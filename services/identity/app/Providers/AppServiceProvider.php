<?php

namespace Mordomus\Identity\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Mordomus\Identity\Auth\JwtGuard;
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
        $this->registerRateLimits();
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
