<?php

use App\Http\Controllers\Api\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['web', 'auth'])->group(function () {
    Route::prefix('v1')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->middleware('throttle:60,1');
    });
});