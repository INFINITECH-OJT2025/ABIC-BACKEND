<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Employee;
use App\Mail\EmployeeCredentialsMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class EmployeeController extends Controller
{
    /**
     * Display a listing of employees (from employees table, joined with users).
     */
    public function index(Request $request)
    {
        try {
            $query = Employee::with('user')
                ->orderBy('created_at', 'desc');

            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('position', 'like', "%{$search}%");
                });
            }

            if ($request->filled('status') && $request->input('status') !== 'all') {
                $status = $request->input('status');
                $query->where('status', $status);
            }

            // When requesting eligible for promotion: approved employees whose user is still role=employee
            if ($request->boolean('eligible_for_promotion')) {
                $query->where('status', 'approved')
                    ->whereHas('user', fn ($q) => $q->where('role', 'employee'));
            }

            $employees = $query->get();

            $transformed = $employees->map(function ($employee) {
                $user = $employee->user;
                return [
                    'id' => $employee->id,
                    'first_name' => $employee->first_name ?? '',
                    'last_name' => $employee->last_name ?? '',
                    'email' => $employee->email ?? $user?->email ?? '',
                    'position' => $employee->position ?? '',
                    'status' => $this->mapStatusForApi($employee->status ?? 'pending'),
                    'created_at' => $employee->created_at->format('Y-m-d'),
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Employees retrieved successfully',
                'data' => $transformed,
            ]);
        } catch (\Exception $e) {
            Log::error('Employee index error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve employees',
                'errors' => null,
            ], 500);
        }
    }

    /**
     * Store a newly created employee: create User (role=employee) + Employee record.
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'first_name' => ['required', 'string', 'max:255', 'min:2'],
                'last_name' => ['required', 'string', 'max:255', 'min:2'],
                'middle_name' => ['nullable', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'position' => ['required', 'string', 'max:255'],
            ], [
                'first_name.required' => 'First name is required',
                'last_name.required' => 'Last name is required',
                'email.required' => 'Email is required',
                'email.email' => 'Please provide a valid email address',
                'email.unique' => 'This email is already registered',
                'position.required' => 'Position is required',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            DB::beginTransaction();

            $name = trim($request->input('first_name') . ' ' . ($request->input('middle_name') ? $request->input('middle_name') . ' ' : '') . $request->input('last_name'));
            $plainPassword = $this->generateRandomPassword();

            $user = User::create([
                'name' => $name,
                'email' => $request->input('email'),
                'password' => Hash::make($plainPassword),
                'role' => 'employee',
                'account_status' => 'inactive',
                'password_expires_at' => now()->addMinutes(30),
                'is_password_expired' => false,
            ]);

            $employee = Employee::create([
                'user_id' => $user->id,
                'first_name' => $request->input('first_name'),
                'last_name' => $request->input('last_name'),
                'middle_name' => $request->input('middle_name'),
                'email' => $request->input('email'),
                'position' => $request->input('position'),
                'status' => 'pending',
                'password' => $plainPassword,
            ]);

            try {
                Mail::to($user->email)->send(new EmployeeCredentialsMail($user, $plainPassword));
                Log::info('Employee credentials email sent', ['email' => $user->email]);
            } catch (\Exception $e) {
                Log::error('Failed to send employee credentials email', [
                    'email' => $user->email,
                    'error' => $e->getMessage(),
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Employee created successfully. Login credentials have been sent to the email.',
                'data' => [
                    'id' => $employee->id,
                    'first_name' => $employee->first_name,
                    'last_name' => $employee->last_name,
                    'email' => $employee->email,
                    'position' => $employee->position,
                    'status' => $this->mapStatusForApi($employee->status),
                    'created_at' => $employee->created_at->format('Y-m-d'),
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Employee store error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to create employee',
                'errors' => null,
            ], 500);
        }
    }

    /**
     * Show a single employee's full profile (super_admin only).
     */
    public function show(Request $request, int $id)
    {
        try {
            $employee = Employee::find($id);
            if (!$employee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee not found',
                    'data' => null,
                ], 404);
            }

            $data = $employee->only([
                'id', 'first_name', 'last_name', 'middle_name', 'suffix',
                'position', 'date_hired', 'birthday', 'birthplace', 'civil_status', 'gender',
                'sss_number', 'philhealth_number', 'pagibig_number', 'tin_number',
                'mlast_name', 'mfirst_name', 'mmiddle_name', 'msuffix',
                'flast_name', 'ffirst_name', 'fmiddle_name', 'fsuffix',
                'mobile_number', 'house_number', 'street', 'village', 'subdivision',
                'barangay', 'region', 'province', 'city_municipality', 'zip_code', 'email_address', 'email',
            ]);
            $data['date_hired'] = $employee->date_hired?->format('Y-m-d');
            $data['birthday'] = $employee->birthday?->format('Y-m-d');
            $data['status'] = $this->mapStatusForApi($employee->status ?? 'pending');
            $data['created_at'] = $employee->created_at?->format('Y-m-d');

            return response()->json([
                'success' => true,
                'message' => 'Employee profile retrieved',
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('Employee show error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to retrieve employee'], 500);
        }
    }

    /**
     * Update an employee by ID (super_admin only).
     */
    public function update(Request $request, int $id)
    {
        try {
            $employee = Employee::find($id);
            if (!$employee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee not found',
                    'data' => null,
                ], 404);
            }

            $allowed = [
                'first_name', 'last_name', 'middle_name', 'suffix',
                'position', 'date_hired', 'birthday', 'birthplace', 'civil_status', 'gender',
                'sss_number', 'philhealth_number', 'pagibig_number', 'tin_number',
                'mlast_name', 'mfirst_name', 'mmiddle_name', 'msuffix',
                'flast_name', 'ffirst_name', 'fmiddle_name', 'fsuffix',
                'mobile_number', 'house_number', 'street', 'village', 'subdivision',
                'barangay', 'region', 'province', 'city_municipality', 'zip_code', 'email_address',
                'status',
            ];

            $updates = $request->only($allowed);

            foreach ($updates as $key => $value) {
                if ($key === 'status') {
                    if (in_array($value, ['pending', 'approved', 'terminated'])) {
                        $employee->status = $value;
                        if ($value === 'approved' && $employee->user_id) {
                            User::where('id', $employee->user_id)->update(['account_status' => 'active']);
                        }
                    }
                } elseif ($value !== null && $value !== '') {
                    $employee->$key = $value;
                }
            }
            $employee->save();

            $data = $employee->fresh()->only([
                'id', 'first_name', 'last_name', 'middle_name', 'suffix',
                'position', 'date_hired', 'birthday', 'birthplace', 'civil_status', 'gender',
                'sss_number', 'philhealth_number', 'pagibig_number', 'tin_number',
                'mlast_name', 'mfirst_name', 'mmiddle_name', 'msuffix',
                'flast_name', 'ffirst_name', 'fmiddle_name', 'fsuffix',
                'mobile_number', 'house_number', 'street', 'village', 'subdivision',
                'barangay', 'region', 'province', 'city_municipality', 'zip_code', 'email_address', 'email',
            ]);
            $data['date_hired'] = $employee->date_hired?->format('Y-m-d');
            $data['birthday'] = $employee->birthday?->format('Y-m-d');
            $data['status'] = $this->mapStatusForApi($employee->status ?? 'pending');

            return response()->json([
                'success' => true,
                'message' => 'Employee updated successfully',
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('Employee update error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to update employee'], 500);
        }
    }

    /**
     * Get current authenticated employee's profile.
     */
    public function me(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user || $user->role !== 'employee') {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $employee = Employee::where('user_id', $user->id)->first();
            if (!$employee) {
                // Create Employee record on first access (e.g. user created before Employee table existed)
                $nameParts = explode(' ', trim($user->name), 2);
                $employee = Employee::create([
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'password' => \Illuminate\Support\Str::random(32),
                    'first_name' => $nameParts[0] ?? 'Employee',
                    'last_name' => $nameParts[1] ?? '',
                    'status' => 'pending',
                ]);
            }

            $data = $employee->only([
                'id', 'first_name', 'last_name', 'middle_name', 'suffix',
                'position', 'date_hired', 'birthday', 'birthplace', 'civil_status', 'gender',
                'sss_number', 'philhealth_number', 'pagibig_number', 'tin_number',
                'mlast_name', 'mfirst_name', 'mmiddle_name', 'msuffix',
                'flast_name', 'ffirst_name', 'fmiddle_name', 'fsuffix',
                'mobile_number', 'house_number', 'street', 'village', 'subdivision',
                'barangay', 'region', 'province', 'city_municipality', 'zip_code', 'email_address', 'email',
            ]);
            $data['date_hired'] = $employee->date_hired?->format('Y-m-d');
            $data['birthday'] = $employee->birthday?->format('Y-m-d');

            return response()->json([
                'success' => true,
                'message' => 'Employee profile retrieved',
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('Employee me error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to retrieve profile'], 500);
        }
    }

    /**
     * Update current authenticated employee's profile.
     */
    public function updateMe(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user || $user->role !== 'employee') {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $employee = Employee::where('user_id', $user->id)->first();
            if (!$employee) {
                return response()->json(['success' => false, 'message' => 'Employee record not found'], 404);
            }

            $allowed = [
                'first_name', 'last_name', 'middle_name', 'suffix',
                'position', 'date_hired', 'birthday', 'birthplace', 'civil_status', 'gender',
                'sss_number', 'philhealth_number', 'pagibig_number', 'tin_number',
                'mlast_name', 'mfirst_name', 'mmiddle_name', 'msuffix',
                'flast_name', 'ffirst_name', 'fmiddle_name', 'fsuffix',
                'mobile_number', 'house_number', 'street', 'village', 'subdivision',
                'barangay', 'region', 'province', 'city_municipality', 'zip_code', 'email_address',
            ];

            $updates = $request->only($allowed);
            foreach ($updates as $key => $value) {
                if ($value !== null) {
                    $employee->$key = $value;
                }
            }
            $employee->save();

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => $employee->fresh()->only($allowed),
            ]);
        } catch (\Exception $e) {
            Log::error('Employee updateMe error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to update profile'], 500);
        }
    }

    /**
     * Map employee status to API format (pending/active/suspended for consistency with users).
     */
    private function mapStatusForApi(string $status): string
    {
        return match ($status) {
            'approved' => 'active',
            'terminated' => 'suspended',
            default => 'pending',
        };
    }

    /**
     * Generate a random password (similar to AdminController).
     */
    private function generateRandomPassword(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
        $password = '';
        for ($i = 0; $i < 12; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $password;
    }
}
