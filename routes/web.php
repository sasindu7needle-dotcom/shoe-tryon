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
        Route::post('logout', [Admin\AuthenticatedSessionController::class, 'destroy'])->name('logout');
        Route::get('/', Admin\DashboardController::class)->name('dashboard');
        Route::resource('products', Admin\ProductController::class)->except('show');
        Route::get('products/{product}/calibration', [Admin\ProductCalibrationController::class, 'edit'])->name('products.calibration.edit');
        Route::put('products/{product}/calibration', [Admin\ProductCalibrationController::class, 'update'])->name('products.calibration.update');
        Route::post('products/{product}/poster', [Admin\ProductPosterController::class, 'store'])->name('products.poster.store');
    });
});
