<?php

use App\Http\Controllers\GameController;
use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::get('/games', [GameController::class,'index'])->name('games.index');
Route::get('/games/{game:slug}', [GameController::class,'show'])->name('games.show');
Route::middleware(['auth'])->group(function () {
    Route::post('/games/{game:slug}/checkout', [OrderController::class,'store'])->middleware('throttle:10,1')->name('orders.store');
    Route::get('/my-orders', [OrderController::class,'index'])->name('orders.index');
    Route::get('/my-orders/{order}/reveal', [OrderController::class,'reveal'])->middleware('throttle:15,1')->name('orders.reveal');
});
