<?php

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\AdminRoleController;
use Modules\User\Http\Controllers\AdminUserController;

Route::middleware(['auth:sanctum', 'active'])->prefix('admin')->name('admin.')->group(function () {
    Route::prefix('users')->name('users.')->group(function () {
        Route::get('/', [AdminUserController::class, 'index'])->name('index');
        Route::post('/', [AdminUserController::class, 'store'])->name('store');
        Route::get('/{userId}', [AdminUserController::class, 'show'])->name('show');
        Route::patch('/{userId}/status', [AdminUserController::class, 'updateStatus'])->name('status.update');

        Route::prefix('/{userId}/role-assignments')->name('role-assignments.')->group(function () {
            Route::get('/', [AdminRoleController::class, 'index'])->name('index');
            Route::post('/', [AdminRoleController::class, 'store'])->name('store');
            Route::delete('/{assignmentId}', [AdminRoleController::class, 'destroy'])->name('destroy');
        });
    });
});
