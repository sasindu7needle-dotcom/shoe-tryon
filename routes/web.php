<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\StorefrontController;
use App\Http\Controllers\TryOnController;
use Illuminate\Support\Facades\Route;

Route::get('/', [StorefrontController::class, 'index'])->name('home');
Route::get('/shoes/{product}', [StorefrontController::class, 'show'])->name('products.show');
Route::get('/try-on/{product}', [TryOnController::class, 'show'])->name('try-on.show');
Route::get('/credits', [StorefrontController::class, 'credits'])->name('credits');

Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('login', [Admin\AuthenticatedSessionController::class, 'create'])->name('login');
        Route::post('login', [Admin\AuthenticatedSessionController::class, 'store'])->middleware('throttle:admin-login');
    });

    Route::middleware(['auth', 'admin'])->group(function () {
        Route::get('generations', [Admin\ModelGenerationController::class, 'index'])->name('generations.index');
        Route::get('generations/create', [Admin\ModelGenerationController::class, 'create'])->name('generations.create');
        Route::post('generations', [Admin\ModelGenerationController::class, 'store'])->middleware('throttle:10,1')->name('generations.store');
        Route::get('generations/{generation}', [Admin\ModelGenerationController::class, 'show'])->name('generations.show');
        Route::get('generations/{generation}/status', [Admin\ModelGenerationController::class, 'status'])->middleware('throttle:60,1')->name('generations.status');
        Route::get('generations/{generation}/asset', [Admin\ModelGenerationController::class, 'asset'])->name('generations.asset');
        Route::post('generations/{generation}/start', [Admin\ModelGenerationController::class, 'start'])->middleware('throttle:5,1')->name('generations.start');
        Route::post('generations/{generation}/resume', [Admin\ModelGenerationController::class, 'resume'])->middleware('throttle:5,1')->name('generations.resume');
        Route::post('generations/{generation}/retry', [Admin\ModelGenerationController::class, 'retry'])->middleware('throttle:5,1')->name('generations.retry');
        Route::post('generations/{generation}/prepare', [Admin\ModelGenerationController::class, 'prepare'])->name('generations.prepare');
        Route::post('logout', [Admin\AuthenticatedSessionController::class, 'destroy'])->name('logout');
        Route::get('/', Admin\DashboardController::class)->name('dashboard');
        Route::resource('products', Admin\ProductController::class)->except('show');
        Route::get('products/{product}/calibration', [Admin\ProductCalibrationController::class, 'edit'])->name('products.calibration.edit');
        Route::put('products/{product}/calibration', [Admin\ProductCalibrationController::class, 'update'])->name('products.calibration.update');
        Route::post('products/{product}/poster', [Admin\ProductPosterController::class, 'store'])->name('products.poster.store');
    });
});
