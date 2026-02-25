<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Bank;
use App\Models\Owner;
use App\Models\BankAccount;

class BankAccountController extends Controller
{

    public function index(Request $request)
    {
        $query = BankAccount::with(['bank', 'owner', 'creator']);

        // Filter by status if provided, otherwise default to ACTIVE
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        } else {
            $query->where('status', 'ACTIVE');
        }

        // Filter by account_type if provided
        if ($request->filled('account_type') && $request->account_type !== 'ALL') {
            $query->where('account_type', $request->account_type);
        }

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('account_name', 'like', "%{$search}%")
                ->orWhere('account_number', 'like', "%{$search}%")
                ->orWhere('account_holder', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'date'); // 'date' or 'name'
        $sortOrder = $request->input('sort_order', 'desc'); // 'asc' or 'desc'
        
        if ($sortBy === 'date') {
            // Sort by created_at (date promoted/created) - desc by default
            $query->orderBy('created_at', $sortOrder === 'asc' ? 'ASC' : 'DESC');
        } elseif ($sortBy === 'name') {
            // Sort alphabetically by account_name - asc by default
            $query->orderBy('account_name', $sortOrder === 'asc' ? 'ASC' : 'DESC');
        } else {
            // Default: newest first
            $query->orderBy('created_at', 'DESC');
        }

        // Orders & Paginates Results
        $perPage = $request->input('per_page', 10);
        // If per_page is 'all' or a very large number, get all results without pagination
        if ($perPage === 'all' || (is_numeric($perPage) && $perPage > 1000)) {
            $accounts = $query->get();
            return response()->json([
                'success' => true,
                'message' => 'Bank accounts retrieved successfully',
                'data' => $accounts
            ]);
        }

        $accounts = $query->paginate((int)$perPage);

        return response()->json([
            'success' => true,
            'message' => 'Bank accounts retrieved successfully',
            'data' => $accounts
        ]);
    }


    public function show($id)
    {
        $account = BankAccount::with(['bank', 'owner'])->find($id);

        if (!$account) {
            return response()->json([
                'success' => false,
                'message' => 'Bank account not found',
                'data' => null
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Bank account retrieved successfully',
            'data' => $account
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'owner_id'        => ['required', 'exists:owners,id'],
            'bank_id'         => ['required_if:account_type,BANK', 'nullable', 'exists:banks,id'],
            'account_name'    => ['required', 'string'],
            'account_number'  => ['required_if:account_type,BANK', 'nullable', 'string', 'unique:bank_accounts,account_number'],
            'account_holder'  => ['required', 'string'],
            'account_type'    => ['required', 'string', 'in:BANK,GCASH,CASH,INTERNAL'],
            'opening_balance' => ['required', 'numeric'],
            'opening_date'    => ['required', 'date'],
            'currency'        => ['required', 'string'],
        ]);

        if (isset($validated['bank_id']) && $validated['bank_id']) {
            $bank = Bank::find($validated['bank_id']);
            if ($bank && $bank->status !== 'ACTIVE') {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected bank is inactive',
                    'data' => null
                ], 400);
            }
        }

        $owner = Owner::find($validated['owner_id']);
        if ($owner->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Selected owner is inactive',
                'data' => null
            ], 400);
        }

        $validated['status'] = 'ACTIVE';
        $validated['created_by'] = auth()->id(); // authenticated user

        // Set bank_id to null if account_type is not BANK
        if ($validated['account_type'] !== 'BANK') {
            $validated['bank_id'] = null;
        }

        $account = BankAccount::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Bank account created successfully',
            'data' => $account
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $account = BankAccount::find($id);

        if (!$account) {
            return response()->json([
                'success' => false,
                'message' => 'Bank account not found',
                'data' => null
            ], 404);
        }

        if ($account->status !== 'ACTIVE') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update inactive account',
                'data' => null
            ], 400);
        }

        $validated = $request->validate([
            'owner_id'        => ['required', 'exists:owners,id'],
            'bank_id'         => ['required_if:account_type,BANK', 'nullable', 'exists:banks,id'],
            'account_name'    => ['required', 'string', 'min:2'],
            'account_number'  => ['required_if:account_type,BANK', 'nullable', 'string', 'unique:bank_accounts,account_number,' . $id],
            'account_holder'  => ['required', 'string', 'min:2'],
            'account_type'    => ['required', 'string', 'in:BANK,GCASH,CASH,INTERNAL'],
            'opening_balance' => ['required', 'numeric'],
            'opening_date'    => ['required', 'date'],
            'currency'        => ['required', 'string', 'max:10'],
        ]);

        // Set bank_id to null if account_type is not BANK
        if ($validated['account_type'] !== 'BANK') {
            $validated['bank_id'] = null;
        }

        $account->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Bank account updated successfully',
            'data' => $account
        ]);
    }

    public function inactive($id)
    {
        $account = BankAccount::find($id);

        if (!$account) {
            return response()->json([
                'success' => false,
                'message' => 'Bank account not found',
                'data' => null
            ], 404);
        }

        if ($account->status === 'INACTIVE') {
            return response()->json([
                'success' => false,
                'message' => 'Already inactive',
                'data' => null
            ], 400);
        }

        $account->update(['status' => 'INACTIVE']);

        return response()->json([
            'success' => true,
            'message' => 'Bank account inactive successfully',
            'data' => $account
        ]);
    }

    public function restore($id)
    {
        $account = BankAccount::find($id);

        if (!$account) {
            return response()->json([
                'success' => false,
                'message' => 'Bank account not found',
                'data' => null
            ], 404);
        }

        if ($account->status === 'ACTIVE') {
            return response()->json([
                'success' => false,
                'message' => 'Already active',
                'data' => null
            ], 400);
        }

        $account->update(['status' => 'ACTIVE']);

        return response()->json([
            'success' => true,
            'message' => 'Bank account restored successfully',
            'data' => $account
        ]);
    }
}
