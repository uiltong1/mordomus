<?php

declare(strict_types=1);

namespace Mordomus\Identity\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Mordomus\Identity\Contracts\Repositories\MembershipRepositoryInterface;
use Mordomus\Identity\Contracts\Repositories\UserRepositoryInterface;
use Mordomus\Identity\Contracts\Services\AuthServiceInterface;
use Mordomus\Identity\Contracts\Services\RefreshTokenServiceInterface;
use Mordomus\Identity\Contracts\Services\TenantProvisionerServiceInterface;
use Mordomus\Identity\Contracts\Services\TokenPackagerServiceInterface;
use Mordomus\Identity\Exceptions\InvalidCredentials;
use Mordomus\Identity\Exceptions\InvalidRefreshToken;
use Mordomus\Identity\Exceptions\MembershipRequired;
use Mordomus\Identity\Http\Resources\UserResource;
use Mordomus\Identity\Models\User;

/**
 * Registro, login, renovação e troca de residência — os três eventos de
 * autenticação que o contrato de log exige saem daqui.
 */
final class AuthService implements AuthServiceInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly MembershipRepositoryInterface $memberships,
        private readonly RefreshTokenServiceInterface $refreshTokens,
        private readonly TokenPackagerServiceInterface $tokens,
        private readonly TenantProvisionerServiceInterface $provisioner,
        private readonly UserResource $resource,
    ) {}

    /** @return array<string, mixed> */
    public function register(Request $request): array
    {
        $user = $this->users->create([
            'name' => $request->input('name'),
            'email' => strtolower($request->input('email')),
            'password_hash' => $request->input('password'),
            'locale' => 'pt_BR',
        ]);

        $provisioned = $this->provisioner->create($user, [
            'name' => $request->input('home_name'),
            'timezone' => $request->input('timezone'),
        ]);

        return $this->session($user, $provisioned['tenant']->id);
    }

    /** @return array<string, mixed> */
    public function login(Request $request): array
    {
        $email = strtolower($request->input('email'));
        $user = $this->users->findByEmail($email);

        if (! $user || ! Hash::check($request->input('password'), $user->password_hash)) {
            Log::warning('auth.login_failed', ['identity' => $email]);

            throw InvalidCredentials::make();
        }

        $tenantId = $request->input('tenant_id');
        $activeMembership = $this->memberships->firstActiveForUser($user);

        if ($tenantId) {
            if (! $user->activeMembershipIn($tenantId)) {
                Log::warning('auth.membership_required', [
                    'user_id' => $user->id,
                    'tenant_id' => $tenantId,
                ]);

                throw MembershipRequired::make('Sem acesso à residência informada.');
            }
        } else {
            $tenantId = $activeMembership?->tenant_id;
        }

        return $this->session($user, $tenantId);
    }

    /** @return array<string, mixed> */
    public function refresh(Request $request): array
    {
        try {
            $rotated = $this->refreshTokens->rotate($request->input('refresh_token'));
        } catch (InvalidRefreshToken $exception) {
            Log::warning('auth.refresh_rejected', ['code' => $exception->errorCode()]);

            throw $exception;
        }

        $user = $rotated['user'];
        $tenantId = $this->memberships->tenantIdOfFirstActiveForUser($user);

        return array_merge(
            $this->tokens->renewedSession($user, $tenantId, $rotated),
            [
                'user' => $this->resource->make($user),
                'tenants' => $this->resource->tenants($user),
            ],
        );
    }

    /** @return array<string, mixed> */
    public function logout(Request $request): array
    {
        if ($token = $request->input('refresh_token')) {
            $this->refreshTokens->revoke($token);
        }

        return ['status' => 'ok'];
    }

    /** @return array<string, mixed> */
    public function switchTenant(Request $request): array
    {
        $user = $request->user();
        $tenantId = $request->input('tenant_id');

        if (! $user instanceof User || ! $user->activeMembershipIn($tenantId)) {
            Log::warning('auth.membership_required', [
                'user_id' => $user instanceof User ? $user->id : null,
                'tenant_id' => $tenantId,
            ]);

            throw MembershipRequired::make('Sem acesso à residência informada.');
        }

        return $this->session($user, $tenantId);
    }

    /** @return array<string, mixed> */
    private function session(User $user, ?string $tenantId): array
    {
        return array_merge($this->tokens->session($user, $tenantId), [
            'user' => $this->resource->make($user),
            'tenants' => $this->resource->tenants($user),
        ]);
    }
}
