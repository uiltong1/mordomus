<?php

namespace Mordomus\Notification\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Mordomus\Notification\Models\NotificationLog;

class IndexNotificationLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'channel' => ['sometimes', 'nullable', Rule::in(NotificationLog::CHANNELS)],
        ];
    }
}
