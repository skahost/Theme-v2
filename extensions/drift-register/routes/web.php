<?php

use DriftRegister\Http\Controllers\RegistrationController;
use DriftRegister\Http\Controllers\SsoController;
use Illuminate\Support\Facades\Route;

// Public (login page). Final URLs: /extensions/drift-register/...
Route::get('/status', [RegistrationController::class, 'status'])->middleware('throttle:60,1')->name('status');
Route::post('/register', [RegistrationController::class, 'register'])->middleware('throttle:drift-register')->name('register');
Route::get('/verify/{token}', [RegistrationController::class, 'verify'])->middleware('throttle:20,1')->name('verify');

// Single sign-on. The callback URL is what you enter at Google / Discord as the redirect URI.
Route::get('/sso/{provider}/redirect', [SsoController::class, 'redirect'])
    ->where('provider', 'google|discord')
    ->middleware('throttle:30,1')
    ->name('sso.redirect');
Route::post('/sso/{provider}/register', [SsoController::class, 'startRegistration'])
    ->where('provider', 'google|discord')
    ->middleware('throttle:drift-register')
    ->name('sso.register');
Route::get('/sso/{provider}/callback', [SsoController::class, 'callback'])
    ->where('provider', 'google|discord')
    ->middleware('throttle:30,1')
    ->name('sso.callback');
