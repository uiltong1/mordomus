<?php

namespace Mordomus\Notification\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Mordomus\Notification\Models\NotificationPreference;

class UpdateNotificationPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quiet_start' => ['sometimes', 'nullable', 'date_format:H:i'],
            'quiet_end' => ['sometimes', 'nullable', 'date_format:H:i'],
            'digest' => ['sometimes', 'nullable', Rule::in(NotificationPreference::DIGESTS)],
            'preferred_hour' => ['sometimes', 'nullable', 'date_format:H:i'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->filled('quiet_start') xor $this->filled('quiet_end')) {
                    $validator->errors()->add(
                        'quiet_end',
                        'A janela de silêncio precisa dos dois lados: início e fim.',
                    );
                }
            },
        ];
    }
}
