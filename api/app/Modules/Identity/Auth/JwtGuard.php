<?php

namespace Mordomus\Identity\Auth;

use Illuminate\Auth\RequestGuard;
use Illuminate\Http\Request;
use Mordomus\Identity\Models\User;
use Mordomus\Identity\Services\JwtVerifier;

/**
 * Guard stateless que resolve o usuário a partir do JWT RS256 no header
 * Authorization: Bearer <token> (config/auth.php → guards.jwt.driver = jwt).
 */
class JwtGuard extends RequestGuard
{
    private ?Request $resolvedRequest = null;

    private ?string $resolvedToken = null;

    /**
     * Resolve o usuário sempre a partir da requisição corrente do container:
     * o guard é cacheado pelo AuthManager entre requisições do mesmo processo
     * (testes de integração) e precisa revalidar cada token novo — e o
     * resolver precisa rodar de novo para expor as claims (`jwt_claims`)
     * ao middleware `tenant`.
     */
    public function user(): mixed
    {
        $request = $this->currentRequest();
        $token = $request->bearerToken();

        if ($this->resolvedRequest !== $request || $this->resolvedToken !== $token) {
            $this->resolvedRequest = $request;
            $this->resolvedToken = $token;
            $this->user = null;
        }

        $this->request = $request;

        return parent::user();
    }

    private function currentRequest(): Request
    {
        if (app()->bound('request')) {
            return app('request');
        }

        return $this->request;
    }

    public static function resolver(JwtVerifier $verifier): callable
    {
        return function (Request $request) use ($verifier) {
            $token = $request->bearerToken();

            if (! $token) {
                return null;
            }

            try {
                $claims = $verifier->verify($token);
            } catch (\Throwable) {
                return null;
            }

            $request->attributes->set('jwt_claims', $claims);

            return User::find($claims['sub']);
        };
    }
}
