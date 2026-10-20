<?php

declare(strict_types=1);

namespace Mordomus\Identity\Contracts\Services;

use Illuminate\Http\Request;

interface ProfileServiceInterface
{
    public function show(Request $request): array;
}
