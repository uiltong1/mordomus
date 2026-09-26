<?php

use Mordomus\Identity\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'service' => config('app.name'),
    'status' => 'ok',
]));

Route::get('/health', HealthController::class);
