<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vizion\Http\Controllers\BundleController;

Route::get('/master', [BundleController::class, 'masterStatus']);
Route::post('/master', [BundleController::class, 'setMaster']);
Route::post('/install-bundled', [BundleController::class, 'install']);
