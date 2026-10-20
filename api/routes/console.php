<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('scheduling:materialize')
    ->dailyAt('02:00')
    ->withoutOverlapping();

Schedule::command('scheduling:publish-due')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command('scheduling:prune', ['--days' => 30])
    ->dailyAt('03:00')
    ->withoutOverlapping();

Schedule::command('financial:project')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
