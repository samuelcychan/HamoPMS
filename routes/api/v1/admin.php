<?php

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\AdminRoleController;

Route::middleware(['auth:sanctum', 'permission:users.manage_roles'])
    ->prefix('admin/users/{userId}/role-assignments')
    ->name('admin.users.role-assignments.')
    ->group(function () {
        Route::get('/', [AdminRoleController::class, 'index'])->name('index');
        Route::post('/', [AdminRoleController::class, 'store'])->name('store');
        Route::delete('/{assignmentId}', [AdminRoleController::class, 'destroy'])->name('destroy');
    });
