<?php

namespace Mordomus\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mordomus\Identity\Exceptions\InvalidRefreshToken;
use Mordomus\Identity\Http\Requests\LoginRequest;
use Mordomus\Identity\Http\Requests\LogoutRequest;
use Mordomus\Identity\Http\Requests\RefreshRequest;
use Mordomus\Identity\Http\Requests\RegisterRequest;
use Mordomus\Identity\Http\Requests\SwitchTenantRequest;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Role;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Identity\Models\TenantPreference;
use Mordomus\Identity\Models\User;
use Mordomus\Identity\Services\JwtIssuer;
use Mordomus\Identity\Services\RefreshTokenService;

/**
 * T1.2.2 (registro/login/logout) e T1.2.3/T1.2.5 (refresh/switch-tenant).
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly JwtIssuer $issuer,
        private readonly RefreshTokenService $refreshTokens,
    ) {
    }

    /** POST /auth/register — cria usuário + primeira residência (owner). */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->input('name'),
            'email' => strtolower($request->input('email')),
            'password_hash' => $request->input('password'),
            'locale' => 'pt_BR',
        ]);

        $tenant = DB::transaction(function () use ($request, $user) {
            $tenant = Tenant::create([
                'name' => $request->input('home_name') ?: 'Casa Principal',
                'timezone' => $request->input('timezone') ?: 'America/Sao_Paulo',
            ]);

            $tenant->preferences()->create([
                'quiet_hours' => TenantPreference::DEFAULT_QUIET_HOURS,
                'channels' => TenantPreference::DEFAULT_CHANNELS,
            ]);

            Membership::create([
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'role_id' => Role::systemByKey(Role::OWNER)->id,
                'status' => Membership::STATUS_ACTIVE,
            ]);

            return $tenant;
        });

        return $this->respondWithTokens($user, $tenant->id, 201);
    }

    /** POST /auth/login */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('email', strtolower($request->input('email')))
            ->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password_hash)) {
            return $this->error($request, 401, 'invalid_credentials', 'E-mail ou senha inválidos.');
        }

        $tenantId = $request->input('tenant_id');
        $activeMembership = $user->memberships()
            ->where('status', Membership::STATUS_ACTIVE)
            ->orderBy('created_at')
            ->first();

        if ($tenantId) {
            if (! $user->activeMembershipIn($tenantId)) {
                return $this->error($request, 403, 'membership_required', 'Sem acesso à residência informada.');
            }
        } else {
            $tenantId = $activeMembership?->tenant_id;
        }

        return $this->respondWithTokens($user, $tenantId);
    }

    /** POST /auth/refresh — rotação de refresh token (T1.2.3). */
    public function refresh(RefreshRequest $request): JsonResponse
    {
        try {
            $rotated = $this->refreshTokens->rotate($request->input('refresh_token'));
        } catch (InvalidRefreshToken $exception) {
            return $this->error($request, 401, $exception->errorCode(), $exception->getMessage());
        }

        $user = $rotated['user'];
        $tenantId = $user->memberships()
            ->where('status', Membership::STATUS_ACTIVE)
            ->orderBy('created_at')
            ->value('tenant_id');

        return response()->json(array_merge(
            $this->issuer->issue($user, $tenantId),
            [
                'refresh_token' => $rotated['new']['plain'],
                'refresh_expires_in' => $rotated['new']['expires_in'],
                'user' => $this->userPayload($user),
                'tenants' => $user->tenantSummaries(),
            ],
        ));
    }

    /** POST /auth/logout — revoga o refresh token enviado. */
    public function logout(LogoutRequest $request): JsonResponse
    {
        if ($token = $request->input('refresh_token')) {
            $this->refreshTokens->revoke($token);
        }

        return response()->json(['status' => 'ok']);
    }

    /** POST /auth/switch-tenant — novo JWT com `tid` atualizado (T1.2.5). */
    public function switchTenant(SwitchTenantRequest $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $request->input('tenant_id');

        if (! $user instanceof User || ! $user->activeMembershipIn($tenantId)) {
            return $this->error($request, 403, 'membership_required', 'Sem acesso à residência informada.');
        }

        return $this->respondWithTokens($user, $tenantId);
    }

    /** Monta a resposta padrão de autenticação. */
    private function respondWithTokens(User $user, ?string $tenantId, int $status = 200): JsonResponse
    {
        $tokens = $this->issuer->issue($user, $tenantId);
        $refresh = $this->refreshTokens->issue($user);

        return response()->json(array_merge($tokens, [
            'refresh_token' => $refresh['plain'],
            'refresh_expires_in' => $refresh['expires_in'],
            'user' => $this->userPayload($user),
            'tenants' => $user->tenantSummaries(),
        ]), $status);
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->locale,
        ];
    }
}
