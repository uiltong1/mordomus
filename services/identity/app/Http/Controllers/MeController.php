<?php

namespace Mordomus\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mordomus\Identity\Models\User;

/**
 * T1.2.9 — perfil + residências + capabilities efetivas.
 */
class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $activeTenantId = $this->activeTenantId($request);
        $activeMembership = $activeTenantId ? $user->activeMembershipIn($activeTenantId) : null;

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'locale' => $user->locale,
                ],
                'active_tenant' => $activeTenantId,
                'tenants' => $user->tenantSummaries(),
                'capabilities' => $activeMembership ? $this->capabilitiesOf($activeMembership) : [],
            ],
        ]);
    }
}
