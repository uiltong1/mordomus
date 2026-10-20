<?php

declare(strict_types=1);

namespace Mordomus\Financial\Contracts\Services;

use Illuminate\Http\Request;

interface BillServiceInterface
{
    public function index(Request $request): array;

    public function show(Request $request, string $billId): array;

    public function store(Request $request): array;

    public function update(Request $request, string $billId): array;
}
