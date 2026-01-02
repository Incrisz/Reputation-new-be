<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SwaggerController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/api/docs', [SwaggerController::class, 'index'])->name('swagger.ui');
Route::get('/api/docs/spec', [SwaggerController::class, 'spec'])->name('swagger.spec');
Route::get('/api/docs/status', [SwaggerController::class, 'status'])->name('swagger.status');
