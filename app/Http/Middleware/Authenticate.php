<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        // For API requests or routes starting with /api, return null to prevent redirect
        if ($request->is('api/*') || $request->expectsJson()) {
            return null;
        }
        
        // For web requests, return null to prevent redirect since we don't have web routes
        return null;
    }

    /**
     * Handle unauthenticated requests for API
     */
    protected function unauthenticated($request, array $guards)
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated - Please login to access this resource'
            ], 401);
        }

        return parent::unauthenticated($request, $guards);
    }
}
