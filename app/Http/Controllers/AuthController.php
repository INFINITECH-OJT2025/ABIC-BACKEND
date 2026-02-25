<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * Get login information for GET requests
     */
    public function loginInfo(Request $request)
    {
        return response()->json([
            'message' => 'Please use POST /api/login for authentication',
            'login_endpoint' => '/api/login',
            'method' => 'POST'
        ], 401);
    }

    /**
     * Simple test route without authentication
     */
    public function testSimple(Request $request)
    {
        return response()->json([
            'message' => 'Simple test route works!',
            'timestamp' => now()->toISOString(),
            'laravel_version' => app()->version(),
        ]);
    }

    /**
     * Test route for debugging authentication
     */
    public function testAuth(Request $request)
    {
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
                'cookie' => $request->header('Cookie') ? substr($request->header('Cookie'), 0, 100) . '...' : null,
            ],
            'sanctum_token' => $request->bearerToken(),
        ]);
    }

    /**
     * Handle login request with password expiration and account status checks
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        // Find user first to check status before authentication
        $user = User::where('email', $validated['email'])->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password',
                'errors' => [
                    'credentials' => ['The provided credentials do not match our records.']
                ]
            ], 401);
        }

        // Check account status
        if ($user->account_status === 'suspended') {
            return response()->json([
                'success' => false,
                'message' => 'Account suspended',
                'errors' => [
                    'account' => ['Your account has been suspended. Please contact your administrator.']
                ]
            ], 403);
        }

        // Check password expiration
        if ($user->password_expires_at && now()->greaterThan($user->password_expires_at)) {
            // Mark as expired
            $user->update([
                'is_password_expired' => true,
                'account_status' => 'expired'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Password expired',
                'errors' => [
                    'password' => ['Your temporary password has expired. Please contact your administrator for new credentials.']
                ],
                'requires_password_reset' => true
            ], 401);
        }

        // Attempt authentication
        if (!Auth::attempt($validated)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password',
                'errors' => [
                    'credentials' => ['The provided credentials do not match our records.']
                ]
            ], 401);
        }

        $user = Auth::user();
        
        // Refresh user data from database to get all fields
        $user = User::find($user->id);
        
        // Debug logging
        \Log::info('Backend Debug - User data before response:', [
            'user_id' => $user->id,
            'user_role' => $user->role,
            'user_account_status' => $user->account_status,
            'user_object' => $user->toArray()
        ]);

        // Check if password change is required
        $requiresPasswordChange = false;
        if ($user->role === 'admin' && $user->last_password_change === null) {
            $requiresPasswordChange = true;
        }
        if ($user->role === 'employee' && $user->last_password_change === null) {
            $requiresPasswordChange = true;
        }
        
        // Check if password is expired
        if ($user->password_expires_at && now()->greaterThan($user->password_expires_at)) {
            $requiresPasswordChange = true;
        }

        // Check if account is inactive (first-time admin)
        if ($user->role === 'admin' && $user->account_status === 'inactive') {
            $requiresPasswordChange = true;
            
            // Set email_verified_at on first login (email verification)
            if (!$user->email_verified_at) {
                $user->email_verified_at = now();
                $user->save();
                
                Log::info('Email verified on first admin login', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'email_verified_at' => now()
                ]);
            }
        }

        // Check if account is inactive (first-time employee)
        if ($user->role === 'employee' && $user->account_status === 'inactive') {
            $requiresPasswordChange = true;
            
            // Set email_verified_at on first login (email verification)
            if (!$user->email_verified_at) {
                $user->email_verified_at = now();
                $user->save();
                
                Log::info('Email verified on first employee login', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'email_verified_at' => now()
                ]);
            }
        }

        // Clear password expiration on successful login (but not for first-time admin/employee login)
        if (($user->password_expires_at || $user->account_status === 'inactive') && !$requiresPasswordChange) {
            $this->clearPasswordExpiration($user);
        }

        // Revoke existing tokens
        $user->tokens()->delete();

        // Create new token
        $token = $user->createToken('auth_token', ['*'], now()->addDays(7))->plainTextToken;

        // Log successful login
        Log::info('User logged in', [
            'user_id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'account_status' => $user->account_status,
            'first_login' => $user->last_password_change === null,
            'logged_in_at' => now()
        ]);

        // Don't auto-activate account on first login - let password change handle it
        // Only clear password expiration if not requiring password change (for non-admin/employee)
        if (($user->password_expires_at || $user->account_status === 'inactive') && !$requiresPasswordChange) {
            $this->clearPasswordExpiration($user);
        }

        $responseData = [
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => 60 * 60 * 24 * 7,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'account_status' => $user->account_status,
                    'first_login' => $user->last_password_change === null,
                    'requires_password_change' => $requiresPasswordChange,
                    'email_verified_at' => $user->email_verified_at,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ]
            ]
        ];
        
        // Debug the response data
        \Log::info('Backend Debug - Response data:', $responseData);
        
        return response()->json($responseData);
    }

    /**
     * Clear password expiration after successful first login
     */
    private function clearPasswordExpiration(User $user): void
    {
        $user->update([
            'password_expires_at' => null,
            'is_password_expired' => false,
            'last_password_change' => now(),
            'account_status' => 'active'
        ]);
    }

    /**
     * Handle logout request
     */
    public function logout(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'No authenticated user found',
                    'errors' => null
                ], 401);
            }

            // Revoke current token
            $user->currentAccessToken()?->delete();

            // Log logout
            Log::info('User logged out', [
                'user_id' => $user->id,
                'email' => $user->email,
                'logged_out_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Logout successful',
                'data' => null
            ]);

        } catch (\Exception $e) {
            Log::error('Logout error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to logout due to server error',
                'errors' => null
            ], 500);
        }
    }

    /**
     * Get current user information
     */
    public function me(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Not authenticated',
                    'errors' => null
                ], 401);
            }

            // Check if password is expired (for ongoing sessions)
            if ($user->password_expires_at && 
                now()->greaterThan($user->password_expires_at) && 
                $user->role !== 'super_admin') {
                    
                // Mark as expired
                $user->update([
                    'is_password_expired' => true,
                    'account_status' => 'expired'
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Password expired',
                    'errors' => [
                        'password' => ['Your temporary password has expired. Please contact your administrator for new credentials.']
                    ],
                    'requires_password_reset' => true
                ], 401);
            }

            return response()->json([
                'success' => true,
                'message' => 'User retrieved successfully',
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'account_status' => $user->account_status,
                    'first_login' => $user->last_password_change === null,
                    'password_expires_at' => $user->password_expires_at,
                    'email_verified_at' => $user->email_verified_at,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Me endpoint error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user information',
                'errors' => null
            ], 500);
        }
    }

    /**
     * Change password for first-time users
     */
    public function changePassword(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Not authenticated',
                    'errors' => null
                ], 401);
            }

            $validated = $request->validate([
                'current_password' => ['required', 'string'],
                'new_password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);

            // Verify current password
            if (!Hash::check($validated['current_password'], $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid current password',
                    'errors' => [
                        'current_password' => ['The current password is incorrect.']
                    ]
                ], 422);
            }

            // Update password
            $updateData = [
                'password' => Hash::make($validated['new_password']),
                'password_expires_at' => null,
                'is_password_expired' => false,
                'last_password_change' => now(),
                'account_status' => 'active'
            ];
            
            $user->update($updateData);

            // Revoke all tokens (force re-login)
            $user->tokens()->delete();

            Log::info('Password changed', [
                'user_id' => $user->id,
                'email' => $user->email,
                'changed_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Password changed successfully. Please login with your new password.',
                'data' => null
            ]);

        } catch (\Exception $e) {
            Log::error('Password change error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to change password due to server error',
                'errors' => null
            ], 500);
        }
    }
}