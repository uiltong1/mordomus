<?php

declare(strict_types=1);

namespace Mordomus\Financial\Contracts\Services;

use Illuminate\Http\Request;

interface BillOccurrenceServiceInterface
{
    public function index(Request $request): array;

    public function store(Request $request): array;

    public function pay(Request $request, string $billOccurrenceId): array;
}
