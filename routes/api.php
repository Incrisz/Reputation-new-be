<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ReputationController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('api')->group(function () {
    // Authentication Endpoints
    Route::post('/auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:10,1')
        ->name('auth.register');

    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('auth.login');

    Route::post('/auth/google', [AuthController::class, 'google'])
        ->middleware('throttle:10,1')
        ->name('auth.google');

    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:5,1')
        ->name('auth.forgot-password');

    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:10,1')
        ->name('auth.reset-password');

    Route::get('/auth/profile', [AuthController::class, 'profile'])
        ->middleware('throttle:30,1')
        ->name('auth.profile');

    Route::put('/auth/profile', [AuthController::class, 'updateProfile'])
        ->middleware('throttle:20,1')
        ->name('auth.profile.update');

    Route::put('/auth/change-password', [AuthController::class, 'changePassword'])
        ->middleware('throttle:10,1')
        ->name('auth.change-password');

    // Reputation Scan Endpoint
    Route::post('/reputation/scan', [ReputationController::class, 'scan'])
        ->middleware('throttle:10,1')
        ->name('reputation.scan');
});
