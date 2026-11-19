<?php

use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\GameKeyController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\OutboxController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

// Public catalogue.
Route::get('/games', [GameController::class, 'index'])->name('games.index');
Route::get('/games/{game:slug}', [GameController::class, 'show'])->name('games.show');

// Customer area: authenticated, and every order route is owner-only (OrderPolicy).
Route::middleware('auth')->group(function () {
    Route::get('/games/{game:slug}/buy', [GameController::class, 'buy'])->name('games.buy');
    Route::post('/games/{game:slug}/checkout', [OrderController::class, 'store'])
        ->middleware('throttle:checkout')->name('orders.store');
    Route::get('/my-orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/my-orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/my-orders/{order}/reveal', [OrderController::class, 'reveal'])
        ->middleware('throttle:reveal-key')->name('orders.reveal');
});

// Operator area: enforced server-side by the admin gate, not by hiding links in the UI.
Route::middleware(['auth', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', AdminDashboardController::class)->name('dashboard');
    Route::get('/orders', [AdminOrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [AdminOrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order}/retry', [AdminOrderController::class, 'retry'])->name('orders.retry');
    Route::post('/orders/{order}/cancel', [AdminOrderController::class, 'cancel'])->name('orders.cancel');
    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::post('/games/{game:slug}/keys', [GameKeyController::class, 'store'])->name('games.keys.store');
    Route::get('/outbox', [OutboxController::class, 'index'])->name('outbox.index');
    Route::post('/outbox/requeue', [OutboxController::class, 'requeueAll'])->name('outbox.requeue-all');
    Route::post('/outbox/{message}/requeue', [OutboxController::class, 'requeue'])
        ->whereNumber('message')->name('outbox.requeue');
});
