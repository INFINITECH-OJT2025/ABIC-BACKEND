<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Employee;
use App\Mail\AdminCredentialsMail;
use App\Mail\AdminPromotionMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    /**
     * Display a listing of admin accounts.
     */
    public function index(Request $request)
    {
        try {
            // Debug logging
            \Log::info('AdminController::index called', [
                'user' => $request->user() ? $request->user()->id : 'No user',
                'user_role' => $request->user()?->role,
                'auth_header' => $request->header('Authorization') ? 'Present' : 'Missing',
                'cookie_header' => $request->header('Cookie') ? 'Present' : 'Missing'
            ]);

            $query = User::where('role', 'admin');

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
                $query->where('account_status', $status === 'Active' ? 'active' : ($status === 'Inactive' ? 'inactive' : 'suspended'));
            }

            // Date range filter
            if ($request->filled('date_from')) {
                $query->whereRaw('DATE(COALESCE(role_changed_at, created_at)) >= ?', [$request->input('date_from')]);
            }
            if ($request->filled('date_to')) {
                $query->whereRaw('DATE(COALESCE(role_changed_at, created_at)) <= ?', [$request->input('date_to')]);
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

            // Email existence check
            if ($request->filled('check_email')) {
                $email = $request->input('check_email');
                $excludeId = $request->input('exclude_id');
                
                $emailQuery = User::where('email', $email)->where('role', 'admin');
                if ($excludeId) {
                    $emailQuery->where('id', '!=', $excludeId);
                }
                
                $exists = $emailQuery->exists();
                
                return response()->json([
                    'success' => true,
                    'message' => 'Email check completed',
                    'data' => [
                        'exists' => $exists
                    ]
                ]);
            }

            // Pagination
            $perPage = $request->input('per_page', 10);
            if ($perPage === 'all' || (is_numeric($perPage) && $perPage > 1000)) {
                $admins = $query->get();
                $transformedAdmins = $admins->map(function ($admin) {
                    $status = 'Active';
                    if ($admin->account_status === 'inactive') {
                        $status = 'Inactive';
                    } elseif ($admin->account_status === 'suspended') {
                        $status = 'Suspended';
                    }
                    
                    return [
                        'id' => $admin->id,
                        'name' => $admin->name,
                        'email' => $admin->email,
                        'status' => $status,
                        'promoted_at' => $admin->role_changed_at?->format('Y-m-d\TH:i:s\Z'),
                        'updated_at' => $admin->updated_at->format('Y-m-d\TH:i:s\Z'),
                    ];
                });

                return response()->json([
                    'success' => true,
                    'message' => 'Admin accounts retrieved successfully',
                    'data' => $transformedAdmins
                ]);
            }

            $admins = $query->paginate((int)$perPage);

            // Transform data for frontend
            $transformedAdmins = $admins->map(function ($admin) {
                $status = 'Active';
                if ($admin->account_status === 'inactive') {
                    $status = 'Inactive';
                } elseif ($admin->account_status === 'suspended') {
                    $status = 'Suspended';
                }
                
                return [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'status' => $status,
                    'promoted_at' => $admin->role_changed_at?->format('Y-m-d\TH:i:s\Z'),
                    'updated_at' => $admin->updated_at->format('Y-m-d\TH:i:s\Z'),
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Admin accounts retrieved successfully',
                'data' => [
                    'data' => $transformedAdmins,
                    'current_page' => $admins->currentPage(),
                    'last_page' => $admins->lastPage(),
                    'per_page' => $admins->perPage(),
                    'total' => $admins->total(),
                    'from' => $admins->firstItem(),
                    'to' => $admins->lastItem(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Admin index error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve admin accounts',
                'errors' => null
            ], 500);
        }
    }

    /**
     * Promote an approved employee to admin.
     */
    public function promoteFromEmployee(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
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
                    'message' => 'Only approved employees can be promoted to admin',
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

            if ($user->role === 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'This employee is already an admin',
                    'errors' => null,
                ], 422);
            }

            DB::beginTransaction();

            // Generate a new readable password for the promoted admin
            $plainPassword = $this->generateReadablePassword();
            
            $user->update([
                'role' => 'admin',
                'role_changed_at' => now(),
                'name' => trim(($employee->first_name ?? '') . ' ' . ($employee->middle_name ?? '') . ' ' . ($employee->last_name ?? '')),
                'password' => Hash::make($plainPassword),
                'password_expires_at' => now()->addMinutes(30), // Must change password within 30 minutes
                'is_password_expired' => false,
            ]);

            DB::commit();

            // Send credentials email with the new password
            try {
                Mail::to($user->email)->send(new AdminCredentialsMail($user->fresh(), $plainPassword));
                Log::info('Admin promotion credentials email sent', ['user_email' => $user->email]);
            } catch (\Exception $e) {
                Log::error('Failed to send admin promotion credentials email', [
                    'user_email' => $user->email,
                    'error' => $e->getMessage(),
                ]);
                // Continue - promotion succeeded even if email fails
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
                'message' => 'Employee promoted to admin successfully',
                'data' => $responseData,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Admin promoteFromEmployee error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to promote employee to admin',
                'errors' => null,
            ], 500);
        }
    }

    /**
     * Revert an admin back to employee (revoke admin promotion).
     */
    public function revertToEmployee($id)
    {
        try {
            $admin = User::where('role', 'admin')->findOrFail($id);

            // Prevent reverting self
            if ($admin->id === auth()->id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot revert your own account',
                    'errors' => null,
                ], 422);
            }

            DB::beginTransaction();

            $admin->update([
                'role' => 'employee',
                'role_changed_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Admin reverted to employee successfully',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Admin account not found',
                'errors' => null,
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Admin revertToEmployee error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to revert admin to employee',
                'errors' => null,
            ], 500);
        }
    }

    /**
     * Store a newly created admin account.
     */
    public function store(Request $request)
    {
        try {
            // Debug logging
            \Log::info('AdminController::store called', [
                'user' => $request->user() ? $request->user()->id : 'No user',
                'user_role' => $request->user()?->role,
                'request_data' => $request->all(),
                'auth_header' => $request->header('Authorization') ? 'Present' : 'Missing',
            ]);

            $validator = Validator::make($request->all(), [
                'name' => ['required', 'string', 'max:255', 'min:2'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            ], [
                'name.required' => 'Name is required',
                'name.min' => 'Name must be at least 2 characters',
                'name.max' => 'Name must not exceed 255 characters',
                'email.required' => 'Email is required',
                'email.email' => 'Please provide a valid email address',
                'email.max' => 'Email must not exceed 255 characters',
                'email.unique' => 'This email is already registered',
            ]);

            if ($validator->fails()) {
                \Log::info('AdminController::store validation failed', ['errors' => $validator->errors()->toArray()]);
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            \Log::info('AdminController::store validation passed', ['validated_data' => $request->only(['name', 'email'])]);

            DB::beginTransaction();

            // Generate a readable password
            $plainPassword = $this->generateReadablePassword();
            
            // Create admin account
            $admin = User::create([
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'password' => Hash::make($plainPassword),
                'role' => 'admin',
                'account_status' => 'inactive', // Inactive until password change
                'password_expires_at' => now()->addMinutes(30), // Must change password within 30 minutes
                'is_password_expired' => false, // Will be true after first login
            ]);

            \Log::info('AdminController::store admin created', [
                'admin_id' => $admin->id,
                'admin_email' => $admin->email,
                'password_generated' => true
            ]);

            // Send credentials email
            try {
                Mail::to($admin->email)->send(new AdminCredentialsMail($admin, $plainPassword));
                Log::info('Credentials email sent successfully', ['admin_email' => $admin->email]);
            } catch (\Exception $e) {
                Log::error('Failed to send credentials email', [
                    'admin_email' => $admin->email,
                    'error' => $e->getMessage()
                ]);
                // Continue even if email fails - admin account is still created
            }

            DB::commit();

            // Transform response data
            $responseData = [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'status' => 'Inactive', // Shows as Inactive until password change
                'promoted_at' => $admin->role_changed_at?->format('Y-m-d\TH:i:s\Z'),
                'updated_at' => $admin->updated_at->format('Y-m-d\TH:i:s\Z'),
            ];

            return response()->json([
                'success' => true,
                'message' => 'Admin account created successfully. Login credentials have been sent to the email.',
                'data' => $responseData
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Admin store error: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create admin account',
                'errors' => null
            ], 500);
        }
    }

    /**
     * Generate a readable password for admin accounts
     */
    private function generateReadablePassword(): string
    {
        $words = ['Admin', 'Access', 'Secure', 'System', 'Portal', 'Dashboard'];
        $numbers = rand(100, 999);
        $specialChars = ['!', '@', '#', '$', '%'];
        
        $word = $words[array_rand($words)];
        $special = $specialChars[array_rand($specialChars)];
        
        return $word . $special . $numbers;
    }

    /**
     * Display the specified admin account.
     */
    public function show($id)
    {
        try {
            $admin = User::where('role', 'admin')->findOrFail($id);

            $status = 'Active';
            if ($admin->account_status === 'inactive') {
                $status = 'Inactive';
            } elseif ($admin->account_status === 'suspended') {
                $status = 'Suspended';
            }

            $responseData = [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'status' => $status,
                'promoted_at' => $admin->role_changed_at?->format('Y-m-d\TH:i:s\Z'),
                'updated_at' => $admin->updated_at->format('Y-m-d\TH:i:s\Z'),
            ];

            return response()->json([
                'success' => true,
                'message' => 'Admin account retrieved successfully',
                'data' => $responseData
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Admin account not found',
                'errors' => null
            ], 404);
            
        } catch (\Exception $e) {
            Log::error('Admin show error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve admin account',
                'errors' => null
            ], 500);
        }
    }

    /**
     * Update the specified admin account.
     */
    public function update(Request $request, $id)
    {
        try {
            $admin = User::where('role', 'admin')->findOrFail($id);

            // Debug logging
            \Log::info('AdminController::update called', [
                'admin_id' => $id,
                'request_data' => $request->all(),
                'user' => $request->user() ? $request->user()->id : 'No user',
            ]);

            $validator = Validator::make($request->all(), [
                'name' => ['sometimes', 'required', 'string', 'max:255', 'min:2'],
                'email' => [
                    'sometimes', 
                    'required', 
                    'email', 
                    'max:255',
                    Rule::unique('users', 'email')->ignore($admin->id)
                ],
                'status' => ['sometimes', 'required', 'string', 'in:Active,Inactive,Suspended'],
            ], [
                'name.required' => 'Name is required',
                'name.min' => 'Name must be at least 2 characters',
                'name.max' => 'Name must not exceed 255 characters',
                'email.required' => 'Email is required',
                'email.email' => 'Please provide a valid email address',
                'email.max' => 'Email must not exceed 255 characters',
                'email.unique' => 'This email is already registered',
                'status.required' => 'Status is required',
                'status.in' => 'Status must be Active, Inactive, or Suspended',
            ]);

            if ($validator->fails()) {
                \Log::info('AdminController::update validation failed', ['errors' => $validator->errors()->toArray()]);
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Prepare update data
            $updateData = [];
            
            if ($request->has('name')) {
                $updateData['name'] = $request->input('name');
            }
            
            if ($request->has('email')) {
                $updateData['email'] = $request->input('email');
            }
            
            if ($request->has('status')) {
                // Convert frontend status to backend account_status
                $status = $request->input('status');
                if ($status === 'Active') {
                    $updateData['account_status'] = 'active';
                } elseif ($status === 'Inactive') {
                    $updateData['account_status'] = 'inactive';
                } elseif ($status === 'Suspended') {
                    $updateData['account_status'] = 'suspended';
                }
            }

            // Only update if there's data to update
            if (!empty($updateData)) {
                $admin->update($updateData);
                \Log::info('AdminController::update admin updated', [
                    'admin_id' => $admin->id,
                    'update_data' => $updateData
                ]);
            }

            DB::commit();

            // Transform response data
            $status = 'Active';
            if ($admin->account_status === 'inactive') {
                $status = 'Inactive';
            } elseif ($admin->account_status === 'suspended') {
                $status = 'Suspended';
            }

            $responseData = [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'status' => $status,
                'promoted_at' => $admin->role_changed_at?->format('Y-m-d\TH:i:s\Z'),
                'updated_at' => $admin->updated_at->format('Y-m-d\TH:i:s\Z'),
            ];

            return response()->json([
                'success' => true,
                'message' => 'Admin account updated successfully',
                'data' => $responseData
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Admin account not found',
                'errors' => null
            ], 404);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Admin update error: ' . $e->getMessage(), [
                'admin_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update admin account',
                'errors' => null
            ], 500);
        }
    }

    /**
     * Remove the specified admin account.
     */
    public function destroy($id)
    {
        try {
            $admin = User::where('role', 'admin')->findOrFail($id);

            // Prevent deletion of the authenticated user
            if ($admin->id === auth()->id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot delete your own account',
                    'errors' => null
                ], 422);
            }

            DB::beginTransaction();

            // Delete related tokens
            $admin->tokens()->delete();
            
            // Delete activation tokens if any
            DB::table('password_reset_tokens')->where('email', $admin->email)->delete();

            // Delete the admin account
            $admin->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Admin account deleted successfully'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Admin account not found',
                'errors' => null
            ], 404);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Admin destroy error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete admin account',
                'errors' => null
            ], 500);
        }
    }

    /**
     * Activate admin account (for email verification)
     */
    public function activate(Request $request, $token)
    {
        try {
            // Find token record
            $tokenRecord = DB::table('password_reset_tokens')
                ->where('token', $token)
                ->first();

            if (!$tokenRecord) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid activation token',
                    'errors' => null
                ], 400);
            }

            // Find admin account
            $admin = User::where('email', $tokenRecord->email)
                ->where('role', 'admin')
                ->first();

            if (!$admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Admin account not found',
                    'errors' => null
                ], 404);
            }

            // Check if already activated
            if ($admin->account_status === 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Account is already activated',
                    'errors' => null
                ], 400);
            }

            DB::beginTransaction();

            // Activate account
            $admin->update([
                'account_status' => 'active',
                'email_verified_at' => now(),
            ]);

            // Delete activation token
            DB::table('password_reset_tokens')->where('email', $admin->email)->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Admin account activated successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Admin activation error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to activate admin account',
                'errors' => null
            ], 500);
        }
    }
}
