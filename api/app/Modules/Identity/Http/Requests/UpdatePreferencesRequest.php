<?php

namespace Mordomus\Identity\Http\Requests;

class UpdatePreferencesRequest extends TenantScopedRequest
{
    protected function requiredCapability(): ?string
    {
        return 'tenant.manage';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'preferred_hour' => ['sometimes', 'date_format:H:i'],
            'quiet_hours' => ['sometimes', 'array'],
            'quiet_hours.start' => ['required_with:quiet_hours', 'date_format:H:i'],
            'quiet_hours.end' => ['required_with:quiet_hours', 'date_format:H:i'],
            'channels' => ['sometimes', 'array'],
            'channels.email' => ['sometimes', 'boolean'],
            'channels.push' => ['sometimes', 'boolean'],
            'channels.in_app' => ['sometimes', 'boolean'],
        ];
    }
}
