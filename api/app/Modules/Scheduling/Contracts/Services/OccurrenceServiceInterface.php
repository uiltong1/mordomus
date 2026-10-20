<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Contracts\Services;

use Illuminate\Http\Request;

interface OccurrenceServiceInterface
{
    public function index(Request $request): array;

    public function complete(Request $request, string $occurrenceId, ?string $subjectType = null): array;

    public function skip(Request $request, string $occurrenceId, ?string $subjectType = null): array;
}
