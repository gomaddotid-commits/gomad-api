<?php

use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'data' => [
        'status' => 'ok',
    ],
]))->name('api.v1.health');
