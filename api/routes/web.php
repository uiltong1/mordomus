<?php

use Illuminate\Support\Facades\Route;
use Mordomus\Http\Controllers\HealthController;
use Mordomus\Http\Controllers\RootController;

Route::get('/', RootController::class);

Route::get('/health', HealthController::class);
