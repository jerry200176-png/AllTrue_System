<?php

use Illuminate\Support\Facades\Route;

// SPA fallback: serve frontend for all non-API routes
Route::get('/{path?}', function () {
    $index = public_path('index.html');
    if (!file_exists($index)) {
        return response()->json(['status' => 'ok', 'hint' => 'Run: cd frontend && npm run deploy']);
    }
    return response()->file($index);
})->where('path', '^(?!api).*');
