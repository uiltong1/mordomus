<?php

declare(strict_types=1);

namespace Mordomus\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Mordomus\Http\Exceptions\ApiException;
use Mordomus\Http\Exceptions\Forbidden;
use Mordomus\Http\Exceptions\TenantMismatch;
use Mordomus\Http\Tenancy\ActiveTenant;
use Mordomus\Identity\Exceptions\MembershipRequired;
use Mordomus\Identity\Models\Membership;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Identity\Models\User;

/**
 * Base de toda rota com `{tenant}`: tenant ativo do token, capability
 * exigida pelo endpoint e membership — nunca no corpo do controller.
 */
abstract class TenantScopedRequest extends FormRequest
{
    private bool $evaluated = false;

    private ?ApiException $failure = null;

    public function authorize(): bool
    {
        return $this->authorizationFailure() === null;
    }

    protected function failedAuthorization(): void
    {
        throw $this->authorizationFailure() ?? Forbidden::make();
    }

    /** Capability que o endpoint exige; null = nenhuma. */
    protected function requiredCapability(): ?string
    {
        return null;
    }

    /** Exige membership ativo mesmo sem capability associada. */
    protected function requiresMembership(): bool
    {
        return false;
    }

    private function authorizationFailure(): ?ApiException
    {
        if (! $this->evaluated) {
            $this->failure = $this->evaluate();
            $this->evaluated = true;
        }

        return $this->failure;
    }

    private function evaluate(): ?ApiException
    {
        $tenant = $this->route('tenant');
        $active = ActiveTenant::id($this);

        if (! $tenant instanceof Tenant || $active === null || $tenant->id !== $active) {
            return TenantMismatch::make();
        }

        $capability = $this->requiredCapability();

        if ($capability !== null && ! $this->user()?->can($capability)) {
            return Forbidden::make();
        }

        if ($this->requiresMembership() && $this->membershipIn($tenant) === null) {
            return MembershipRequired::make();
        }

        return null;
    }

    private function membershipIn(Tenant $tenant): ?Membership
    {
        $user = $this->user();

        return $user instanceof User ? $user->activeMembershipIn($tenant->id) : null;
    }
}
