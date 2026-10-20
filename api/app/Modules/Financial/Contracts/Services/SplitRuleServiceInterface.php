<?php

declare(strict_types=1);

namespace Mordomus\Financial\Contracts\Services;

use Illuminate\Http\Request;

interface SplitRuleServiceInterface
{
    public function index(Request $request): array;

    public function update(Request $request): array;
}
