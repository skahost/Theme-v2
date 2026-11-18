<?php

use DriftRegister\Http\Controllers\SsoController;
use Illuminate\Support\Facades\Route;

// Signed-in users only. Final URL: /extensions/drift-register/sso/{provider}/link
Route::get('/sso/{provider}/link', [SsoController::class, 'link'])
    ->where('provider', 'google|discord')
    ->middleware('throttle:20,1')
    ->name('sso.link');
