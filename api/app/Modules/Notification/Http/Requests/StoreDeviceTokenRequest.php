<?php

namespace Mordomus\Notification\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Mordomus\Notification\Models\DeviceToken;

class StoreDeviceTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'platform' => ['sometimes', Rule::in(DeviceToken::PLATFORMS)],
            'endpoint' => ['required', 'url', 'max:2048'],
            'p256dh' => ['required', 'string', 'max:256', 'regex:/^[A-Za-z0-9\-_=]+$/'],
            'auth' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-_=]+$/'],
        ];
    }
}
