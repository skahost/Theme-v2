<?php

use DriftRegister\Http\Controllers\RegistrationController;
use Illuminate\Support\Facades\Route;

// Final URL: /register (the prefix comes from routes.root in extension.json).
// The form posts to the existing /extensions/drift-register/register endpoint.
Route::get('/', [RegistrationController::class, 'show'])->name('show');
