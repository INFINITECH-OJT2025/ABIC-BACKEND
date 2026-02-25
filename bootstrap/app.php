<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function ($middleware) {
        $middleware->statefulApi();
        
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Handle authentication exceptions for API routes
        $exceptions->renderable(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            // Check if this is an API route
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated - Please login to access this resource'
                ], 401);
            }
        });

        // Handle authorization exceptions
        $exceptions->renderable(function (\Illuminate\Auth\Access\AuthorizationException $e, \Illuminate\Http\Request $request) {
            // Check if this is an API route
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden - You do not have permission to access this resource'
                ], 403);
            }
        });

        // Handle RouteNotFoundException specifically
        $exceptions->renderable(function (\Symfony\Component\Routing\Exception\RouteNotFoundException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Route not found',
                    'requested_url' => $request->fullUrl(),
                    'available_routes' => [
                        'GET /api/test-simple',
                        'GET /api/test-auth',
                        'GET /api/login',
                        'POST /api/login',
                        'GET /api/admin/accounts',
                        'POST /api/admin/accounts'
                    ]
                ], 404);
            }
        });
    })->create();
