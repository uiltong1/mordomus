<?php

use Illuminate\Support\Facades\Route;
use Mordomus\Http\Controllers\HealthController;

Route::get('/', fn () => response()->json([
    'service' => config('app.name'),
    'status' => 'ok',
]));

Route::get('/health', HealthController::class);
