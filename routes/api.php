<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminPlanController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ReputationController;
use App\Http\Controllers\UserPlanController;

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

    Route::post('/auth/logout', [AuthController::class, 'logout'])
        ->middleware('throttle:10,1')
        ->name('auth.logout');

    // Reputation Scan Endpoint
    Route::post('/audit/start', [ReputationController::class, 'scan'])
        ->middleware('throttle:10,1')
        ->name('audit.start');

    Route::post('/reputation/scan', [ReputationController::class, 'scan'])
        ->middleware('throttle:10,1')
        ->name('reputation.scan');

    Route::get('/reputation/history', [ReputationController::class, 'history'])
        ->middleware('throttle:30,1')
        ->name('reputation.history');

    Route::get('/reputation/history/{audit}', [ReputationController::class, 'historyItem'])
        ->middleware('throttle:30,1')
        ->name('reputation.history.item');

    // Plans / Subscription / Usage Endpoints
    Route::get('/plans', [UserPlanController::class, 'plans'])
        ->middleware('throttle:30,1')
        ->name('plans.list');

    Route::get('/user/current-plan', [UserPlanController::class, 'currentPlan'])
        ->middleware('throttle:30,1')
        ->name('user.current-plan');

    Route::get('/user/usage-stats', [UserPlanController::class, 'usageStats'])
        ->middleware('throttle:30,1')
        ->name('user.usage-stats');

    Route::get('/user/subscription', [UserPlanController::class, 'subscription'])
        ->middleware('throttle:30,1')
        ->name('user.subscription');

    // Admin-ready plan management endpoints (guarded by X-Admin-Key)
    Route::post('/admin/plans/custom', [AdminPlanController::class, 'createCustomPlan'])
        ->middleware('throttle:20,1')
        ->name('admin.plans.custom');

    Route::post('/admin/company-plan-allocations', [AdminPlanController::class, 'upsertCompanyAllocation'])
        ->middleware('throttle:20,1')
        ->name('admin.company-plan-allocations.upsert');

    Route::get('/admin/company-plan-allocations', [AdminPlanController::class, 'companyAllocations'])
        ->middleware('throttle:20,1')
        ->name('admin.company-plan-allocations.list');
});
