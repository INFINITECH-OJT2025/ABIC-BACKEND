<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Simple test route to debug authentication
Route::get('/test-auth', function (Request $request) {
    return response()->json([
        'authenticated' => $request->user() !== null,
        'user' => $request->user() ? [
            'id' => $request->user()->id,
            'name' => $request->user()->name,
            'email' => $request->user()->email,
            'role' => $request->user()->role,
        ] : null,
        'headers' => [
            'authorization' => $request->header('Authorization'),
            'cookie' => $request->header('Cookie'),
        ],
        'sanctum_token' => $request->bearerToken(),
    ]);
});
