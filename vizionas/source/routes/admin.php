<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vizion\Http\Controllers\BundleController;

Route::post('/install-bundled', [BundleController::class, 'install']);
