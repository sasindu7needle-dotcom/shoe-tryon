<?php

use App\Http\Controllers\Api;
use Illuminate\Support\Facades\Route;

Route::get('products', [Api\ProductController::class, 'index'])->name('api.products.index');

Route::post('try-on-events', [Api\TryOnEventController::class, 'store'])
    ->middleware('throttle:try-on-events')
    ->name('api.try-on-events.store');
