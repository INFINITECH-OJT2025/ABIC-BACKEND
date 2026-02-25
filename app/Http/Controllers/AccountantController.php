<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Mail\AccountantCredentialsMail;
use App\Mail\AccountantPromotionMail;

class AccountantController extends Controller
{
    /**
     * Generate a secure random password
     */
    private function generateSecurePassword(int $length = 12): string
    {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';
        
        // Ensure at least one character from each category
        $password .= Str::lower(Str::random(1)); // lowercase
        $password .= Str::upper(Str::random(1)); // uppercase
        $password .= Str::random(1, '0123456789'); // number
        $password .= Str::random(1, '!@#$%^&*'); // symbol
        
        // Fill the rest with random characters
        for ($i = 4; $i < $length; $i++) {
            $password .= $characters[random_int(0, strlen($characters) - 1)];
        }
        
        return str_shuffle($password);
    }

    /**
     * Send accountant credentials via email
     */
    private function sendAccountantCredentials(User $accountant, string $plainPassword): bool
    {
        try {
            Mail::to($accountant->email)->send(new AccountantCredentialsMail($accountant, $plainPassword));
            return true;
        } catch (\Exception $e) {
            \Log::error('Failed to send accountant credentials email: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Set password expiration for new accounts
     */
    private function setPasswordExpiration(User $user): void
    {
        $user->password_expires_at = now()->addMinutes(30);
        $user->is_password_expired = false;
        $user->account_status = 'inactive';
        $user->save();
    }

    /**
     * Clear password expiration after successful first login
     */
    private function clearPasswordExpiration(User $user): void
    {
        $user->password_expires_at = null;
        $user->is_password_expired = false;
        $user->last_password_change = now();
        $user->account_status = 'active';
        $user->save();
    }

    // ✅ List all accountants
    public function index(Request $request)
    {
        $query = User::where('role', 'accountant');

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Status filter
        if ($request->filled('status') && $request->input('status') !== 'all') {
            $status = $request->input('status');
            $query->where('account_status', $status === 'Active' ? 'active' : ($status === 'Inactive' ? 'inactive' : ($status === 'Suspended' ? 'suspended' : ($status === 'Pending' ? 'pending' : 'expired'))));
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'date'); // 'date' or 'name'
        $sortOrder = $request->input('sort_order', 'desc'); // 'asc' or 'desc'
        
        if ($sortBy === 'date') {
            // Sort by promoted date (newest first by default)
            $query->orderByRaw('COALESCE(role_changed_at, created_at) ' . ($sortOrder === 'asc' ? 'ASC' : 'DESC'));
        } elseif ($sortBy === 'name') {
            // Sort alphabetically by name
            $query->orderBy('name', $sortOrder === 'asc' ? 'ASC' : 'DESC');
        } else {
            // Default: newest first
            $query->orderByRaw('COALESCE(role_changed_at, created_at) DESC');
        }

        // Pagination
        $perPage = $request->input('per_page', 10);
        if ($perPage === 'all' || (is_numeric($perPage) && $perPage > 1000)) {
            $users = $query->get();
            $data = $users->map(function ($user) {
                $status = 'Active';
                if ($user->account_status === 'inactive') {
                    $status = 'Inactive';
                } elseif ($user->account_status === 'suspended') {
                    $status = 'Suspended';
                } elseif ($user->account_status === 'pending') {
                    $status = 'Pending';
                } elseif ($user->account_status === 'expired') {
                    $status = 'Expired';
                }
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => $status,
                    'account_status' => $user->account_status,
                    'promoted_at' => $user->role_changed_at?->format('Y-m-d\TH:i:s\Z'),
                    'updated_at' => $user->updated_at->format('Y-m-d\TH:i:s\Z'),
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Accountants retrieved successfully',
                'data' => $data
            ]);
        }

        $users = $query->paginate((int)$perPage);

        $data = $users->map(function ($user) {
            $status = 'Active';
            if ($user->account_status === 'inactive') {
                $status = 'Inactive';
            } elseif ($user->account_status === 'suspended') {
                $status = 'Suspended';
            } elseif ($user->account_status === 'pending') {
                $status = 'Pending';
            } elseif ($user->account_status === 'expired') {
                $status = 'Expired';
            }
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $status,
                'account_status' => $user->account_status,
                'promoted_at' => $user->role_changed_at?->format('Y-m-d\TH:i:s\Z'),
                'updated_at' => $user->updated_at->format('Y-m-d\TH:i:s\Z'),
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Accountants retrieved successfully',
            'data' => [
                'data' => $data,
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'from' => $users->firstItem(),
                'to' => $users->lastItem(),
            ]
        ]);
    }

    // ✅ Show one accountant
    public function show($id)
    {
        $user = User::where('role', 'accountant')->find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Accountant not found',
                'errors' => null
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Accountant retrieved successfully',
            'data' => $user
        ]);
    }

    // ✅ Create accountant user with auto-generated password and email
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:150|min:2',
                'email' => 'required|email|unique:users,email|max:255',
            ]);

            // Generate secure random password
            $plainPassword = $this->generateSecurePassword(12);
            $hashedPassword = Hash::make($plainPassword);

            // Create the accountant user
            $accountant = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $hashedPassword,
                'role' => 'accountant',
                'email_verified_at' => now(), // Auto-verify since we're sending credentials
                'account_status' => 'inactive'
            ]);

            // Set password expiration
            $this->setPasswordExpiration($accountant);

            // Send credentials via email
            $emailSent = $this->sendAccountantCredentials($accountant, $plainPassword);

            // Log the activity for security purposes
            \Log::info('Accountant account created', [
                'accountant_id' => $accountant->id,
                'accountant_email' => $accountant->email,
                'created_by' => auth()->user()->id,
                'email_sent' => $emailSent,
                'password_expires_at' => $accountant->password_expires_at,
                'created_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => $emailSent 
                    ? 'Accountant created successfully. Credentials have been sent to their email and will expire in 30 minutes.'
                    : 'Accountant created successfully, but there was an issue sending the email. Please contact the accountant directly.',
                'data' => [
                    'id' => $accountant->id,
                    'name' => $accountant->name,
                    'email' => $accountant->email,
                    'role' => $accountant->role,
                    'account_status' => $accountant->account_status,
                    'password_expires_at' => $accountant->password_expires_at,
                    'created_at' => $accountant->created_at,
                    'email_sent' => $emailSent
                ]
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to create accountant: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create accountant due to server error',
                'errors' => null
            ], 500);
        }
    }

    // ✅ Update accountant
    public function update(Request $request, $id)
    {
        try {
            $user = User::where('role', 'accountant')->find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accountant not found',
                    'errors' => null
                ], 404);
            }

            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:150|min:2',
                'email' => 'sometimes|required|email|unique:users,email,' . $id . '|max:255',
                'password' => 'nullable|min:8',
                'send_new_password' => 'sometimes|boolean'
            ]);

            $emailSent = false;
            $newPasswordMessage = '';

            // Handle password update
            if (!empty($validated['password'])) {
                $plainPassword = $validated['password'];
                $validated['password'] = Hash::make($plainPassword);
                
                // Send new password via email if requested
                if (!empty($validated['send_new_password'])) {
                    $emailSent = $this->sendAccountantCredentials($user, $plainPassword);
                    $newPasswordMessage = $emailSent 
                        ? ' New password has been sent to their email.'
                        : ' New password set, but there was an issue sending the email.';
                }
                
                // Log password change for security
                \Log::info('Accountant password updated', [
                    'accountant_id' => $user->id,
                    'updated_by' => auth()->user()->id,
                    'email_sent' => $emailSent,
                    'updated_at' => now()
                ]);
            } else {
                unset($validated['password']);
            }

            // Remove the send_new_password field as it's not a model attribute
            unset($validated['send_new_password']);

            $user->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Accountant updated successfully.' . $newPasswordMessage,
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'account_status' => $user->account_status,
                    'updated_at' => $user->updated_at,
                    'password_updated' => !empty($validated['password']),
                    'email_sent' => $emailSent
                ]
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to update accountant: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update accountant due to server error',
                'errors' => null
            ], 500);
        }
    }

    // ✅ Delete accountant
    public function destroy($id)
    {
        try {
            $user = User::where('role', 'accountant')->find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accountant not found',
                    'errors' => null
                ], 404);
            }

            // Log the deletion for security purposes
            \Log::info('Accountant account deleted', [
                'accountant_id' => $user->id,
                'accountant_email' => $user->email,
                'deleted_by' => auth()->user()->id,
                'deleted_at' => now()
            ]);

            $user->delete();

            return response()->json([
                'success' => true,
                'message' => 'Accountant deleted successfully',
                'data' => null
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to delete accountant: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete accountant due to server error',
                'errors' => null
            ], 500);
        }
    }

    /**
     * Resend accountant credentials (with new password and 30-minute expiration)
     */
    public function resendCredentials(Request $request, $id)
    {
        try {
            $user = User::where('role', 'accountant')->find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accountant not found',
                    'errors' => null
                ], 404);
            }

            // Generate a new temporary password
            $plainPassword = $this->generateSecurePassword(12);
            $hashedPassword = Hash::make($plainPassword);

            // Update user password and reset expiration
            $user->update([
                'password' => $hashedPassword,
                'last_password_change' => null // Reset to force first login
            ]);

            // Set new password expiration
            $this->setPasswordExpiration($user);

            // Send new credentials via email
            $emailSent = $this->sendAccountantCredentials($user, $plainPassword);

            // Log the activity
            \Log::info('Accountant credentials resent', [
                'accountant_id' => $user->id,
                'accountant_email' => $user->email,
                'resent_by' => auth()->user()->id,
                'email_sent' => $emailSent,
                'password_expires_at' => $user->password_expires_at,
                'resent_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => $emailSent 
                    ? 'New credentials have been sent to the accountant\'s email and will expire in 30 minutes.'
                    : 'Password reset, but there was an issue sending the email.',
                'data' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'account_status' => $user->account_status,
                    'password_expires_at' => $user->password_expires_at,
                    'email_sent' => $emailSent
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to resend accountant credentials: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to resend credentials due to server error',
                'errors' => null
            ], 500);
        }
    }

    /**
     * Suspend accountant account
     */
    public function suspend(Request $request, $id)
    {
        try {
            $user = User::where('role', 'accountant')->find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accountant not found',
                    'errors' => null
                ], 404);
            }

            $validated = $request->validate([
                'reason' => 'required|string|max:500'
            ]);

            // Update user status
            $user->update([
                'account_status' => 'suspended',
            ]);

            // Log the suspension
            \Log::info('Accountant account suspended', [
                'accountant_id' => $user->id,
                'accountant_email' => $user->email,
                'suspended_by' => auth()->user()->id,
                'reason' => $validated['reason'],
                'suspended_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Accountant suspended successfully',
                'data' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'account_status' => $user->account_status,
                ]
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to suspend accountant: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to suspend accountant due to server error',
                'errors' => null
            ], 500);
        }
    }

    /**
     * Promote an approved employee to accountant.
     */
    public function promoteFromEmployee(Request $request)
    {
        try {
            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'employee_id' => ['required', 'integer', 'exists:employees,id'],
            ], [
                'employee_id.required' => 'Employee ID is required',
                'employee_id.exists' => 'Employee not found',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $employee = Employee::with('user')->findOrFail($request->input('employee_id'));

            if ($employee->status !== 'approved') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only approved employees can be promoted to accountant',
                    'errors' => null,
                ], 422);
            }

            if (!$employee->user_id || !$employee->user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee has no linked user account',
                    'errors' => null,
                ], 422);
            }

            $user = $employee->user;

            if ($user->role === 'accountant') {
                return response()->json([
                    'success' => false,
                    'message' => 'This employee is already an accountant',
                    'errors' => null,
                ], 422);
            }

            DB::beginTransaction();

            // Generate a new secure password for the promoted accountant
            $plainPassword = $this->generateSecurePassword(12);
            
            $user->update([
                'role' => 'accountant',
                'role_changed_at' => now(),
                'name' => trim(($employee->first_name ?? '') . ' ' . ($employee->middle_name ?? '') . ' ' . ($employee->last_name ?? '')),
                'password' => Hash::make($plainPassword),
                'password_expires_at' => now()->addMinutes(30), // Must change password within 30 minutes
                'is_password_expired' => false,
            ]);

            DB::commit();

            // Send credentials email with the new password
            try {
                Mail::to($user->email)->send(new AccountantCredentialsMail($user->fresh(), $plainPassword));
                Log::info('Accountant promotion credentials email sent', ['user_email' => $user->email]);
            } catch (\Exception $e) {
                Log::error('Failed to send accountant promotion credentials email', [
                    'user_email' => $user->email,
                    'error' => $e->getMessage(),
                ]);
            }

            $status = 'Active';
            if ($user->account_status === 'inactive') {
                $status = 'Inactive';
            } elseif ($user->account_status === 'suspended') {
                $status = 'Suspended';
            }

            $responseData = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $status,
                'promoted_at' => $user->role_changed_at?->format('Y-m-d\TH:i:s\Z'),
                'updated_at' => $user->updated_at->format('Y-m-d\TH:i:s\Z'),
            ];

            return response()->json([
                'success' => true,
                'message' => 'Employee promoted to accountant successfully',
                'data' => $responseData,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Accountant promoteFromEmployee error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to promote employee to accountant',
                'errors' => null,
            ], 500);
        }
    }

    /**
     * Revert an accountant back to employee (revoke accountant promotion).
     */
    public function revertToEmployee($id)
    {
        try {
            $accountant = User::where('role', 'accountant')->findOrFail($id);

            if ($accountant->id === auth()->id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot revert your own account',
                    'errors' => null,
                ], 422);
            }

            DB::beginTransaction();

            $accountant->update([
                'role' => 'employee',
                'role_changed_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Accountant reverted to employee successfully',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Accountant account not found',
                'errors' => null,
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Accountant revertToEmployee error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to revert accountant to employee',
                'errors' => null,
            ], 500);
        }
    }

    /**
     * Unsuspend accountant account
     */
    public function unsuspend(Request $request, $id)
    {
        try {
            $user = User::where('role', 'accountant')->find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accountant not found',
                    'errors' => null
                ], 404);
            }

            // Update user status
            $user->update([
                'account_status' => 'active',
            ]);

            // Log the unsuspension
            \Log::info('Accountant account unsuspended', [
                'accountant_id' => $user->id,
                'accountant_email' => $user->email,
                'unsuspended_by' => auth()->user()->id,
                'unsuspended_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Accountant unsuspended successfully',
                'data' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'account_status' => $user->account_status
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to unsuspend accountant: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to unsuspend accountant due to server error',
                'errors' => null
            ], 500);
        }
    }
}