<?php

use App\Http\Controllers\Admin\AdminPlanController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\AdminUserController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')
    ->middleware(['admin.role'])
    ->group(function (): void {
        Route::get('/plans', [AdminPlanController::class, 'index'])
            ->middleware('throttle:30,1')
            ->name('admin.plans.index');

        Route::post('/plans', [AdminPlanController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('admin.plans.store');

        Route::put('/plans/{plan}', [AdminPlanController::class, 'update'])
            ->middleware('throttle:20,1')
            ->name('admin.plans.update');

        Route::delete('/plans/{plan}', [AdminPlanController::class, 'destroy'])
            ->middleware('throttle:20,1')
            ->name('admin.plans.destroy');

        Route::get('/settings/env', [AdminSettingsController::class, 'show'])
            ->middleware('throttle:20,1')
            ->name('admin.settings.env.show');

        Route::put('/settings/env', [AdminSettingsController::class, 'update'])
            ->middleware('throttle:20,1')
            ->name('admin.settings.env.update');

        Route::get('/users', [AdminUserController::class, 'index'])
            ->middleware('throttle:30,1')
            ->name('admin.users.index');

        Route::get('/users/{user}', [AdminUserController::class, 'show'])
            ->middleware('throttle:30,1')
            ->name('admin.users.show');

        Route::get('/users/{user}/audits/{auditRun}', [AdminUserController::class, 'audit'])
            ->middleware('throttle:30,1')
            ->name('admin.users.audits.show');

        Route::post('/plans/custom', [AdminPlanController::class, 'createCustomPlan'])
            ->middleware('throttle:20,1')
            ->name('admin.plans.custom');

        Route::post('/company-plan-allocations', [AdminPlanController::class, 'upsertCompanyAllocation'])
            ->middleware('throttle:20,1')
            ->name('admin.company-plan-allocations.upsert');

        Route::get('/company-plan-allocations', [AdminPlanController::class, 'companyAllocations'])
            ->middleware('throttle:20,1')
            ->name('admin.company-plan-allocations.list');
    });
