<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('index');
Route::get('/evaluations/{evaluation}', [DashboardController::class, 'show'])->name('evaluation');
Route::get('/{agent}', [DashboardController::class, 'agent'])->name('agent');
