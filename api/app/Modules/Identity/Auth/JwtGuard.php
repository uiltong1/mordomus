<?php

namespace Mordomus\Identity\Auth;

use Illuminate\Auth\RequestGuard;
use Illuminate\Http\Request;
use Mordomus\Identity\Contracts\Services\JwtVerifierServiceInterface;
use Mordomus\Identity\Models\User;

class JwtGuard extends RequestGuard
{
    private ?Request $resolvedRequest = null;

    private ?string $resolvedToken = null;

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

    public static function resolver(JwtVerifierServiceInterface $verifier): callable
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
