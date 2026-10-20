<?php

namespace Mordomus\Financial\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Mordomus\Http\Exceptions\Forbidden;

class ShowSplitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->canManage() || $this->canViewOwn();
    }

    protected function failedAuthorization(): void
    {
        throw Forbidden::make();
    }

    public function rules(): array
    {
        return [];
    }

    public function seesEveryShare(): bool
    {
        return $this->canManage();
    }

    private function canManage(): bool
    {
        return (bool) $this->user()?->can('splits.manage');
    }

    private function canViewOwn(): bool
    {
        return (bool) $this->user()?->can('splits.view_own');
    }
}
