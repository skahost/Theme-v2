<?php

use DriftRegister\Http\Controllers\SsoConnectionsController;
use Illuminate\Support\Facades\Route;

// Final URLs: /api/client/extensions/drift-register/sso (signed-in users).
Route::get('/sso', [SsoConnectionsController::class, 'index'])->name('sso.index');
Route::delete('/sso/{provider}', [SsoConnectionsController::class, 'destroy'])->where('provider', 'google|discord')->name('sso.destroy');
