<?php

use DriftRegister\Http\Controllers\ConfigController;
use DriftRegister\Http\Controllers\UserDetailsController;
use Illuminate\Support\Facades\Route;

// Final URLs: /api/admin/extensions/drift-register/config
// ("/settings" is reserved by the Panel, so this uses "/config".)
Route::get('/config', [ConfigController::class, 'show'])->name('config.show');
Route::put('/config', [ConfigController::class, 'update'])->name('config.update');

// Data for the Drift Register tab of /panel/users/{id}: /api/admin/extensions/drift-register/users/{userId}
// (the parameter is not called {user}, so it can never be mistaken for the Panel's own user binding)
Route::get('/users/{userId}', [UserDetailsController::class, 'show'])->where('userId', '[0-9]+')->name('users.show');
Route::put('/users/{userId}', [UserDetailsController::class, 'update'])->where('userId', '[0-9]+')->name('users.update');
