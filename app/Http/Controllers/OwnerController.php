<?php

namespace App\Http\Controllers;

use App\Models\Owner;
use App\Models\Transaction;
use App\Models\OwnerLedgerEntry;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use \Exception;

class OwnerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            // Start a Query Builder/ Prepares only
            $query = Owner::query();

            // Filter by status if provided and not "ALL"
            if ($request->filled('status') && strtoupper($request->status) !== 'ALL') {
                $status = strtoupper($request->status);
                $validStatuses = ['ACTIVE', 'INACTIVE', 'SUSPENDED'];
                if (!in_array($status, $validStatuses)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid status. Must be one of: ACTIVE, INACTIVE, SUSPENDED, or ALL',
                        'data' => null
                    ], 400);
                }
                $query->where('status', $status);
            }

            // Filter by owner type if provided
            if ($request->filled('owner_type') && $request->owner_type !== 'ALL') {
                $query->where('owner_type', $request->owner_type);
            }

            // APPLIES SEARCH FILTERING
            if ($request->filled('search')) 
            {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                        $q->where('owner_type', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%");
                    });
            }

            // Sorting
            $sortBy = $request->input('sort_by', 'date'); // 'date' or 'name'
            $sortOrder = $request->input('sort_order', 'desc'); // 'asc' or 'desc'
            
            if ($sortBy === 'date') {
                // Sort by created_at (date created) - desc by default
                $query->orderBy('created_at', $sortOrder === 'asc' ? 'ASC' : 'DESC');
            } elseif ($sortBy === 'name') {
                // Sort alphabetically by name - asc by default
                $query->orderBy('name', $sortOrder === 'asc' ? 'ASC' : 'DESC');
            } else {
                // Default: newest first
                $query->orderBy('created_at', 'DESC');
            }

            // Orders & Paginates Results
            $perPage = $request->input('per_page', 10);
            
            // If per_page is 'all' or a very large number, get all results without pagination
            if ($perPage === 'all' || (is_numeric($perPage) && $perPage > 1000)) {
                $owners = $query->get();
                return response()->json([
                    'success' => true,
                    'message' => 'Owners retrieved successfully',
                    'data' => $owners
                ]);
            }
            
            $owners = $query->paginate((int)$perPage);

            return response()->json([
                'success' => true,
                'message' => 'Owners retrieved successfully',
                'data' => $owners
            ]);

        }
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Something went wrong',
                'data' => null
            ], 500);
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function createOwner(Request $request)
    {
        try {
            $validated = $request->validate([
                'owner_type' => ['required', 'string', 'max:30', 'in:COMPANY,CLIENT,MAIN'],
                'name' => ['required', 'string', 'min:2', 'unique:owners,name'],
                'description' => ['nullable', 'string'],
                'email' => ['nullable', 'email', 'unique:owners,email'],
                'phone' => ['nullable', 'string', 'max:100'],
                'phone_number' => ['nullable', 'string', 'max:100'],
                'address' => ['nullable', 'string'],
                'opening_balance' => [
                    'nullable',
                    'numeric',
                    'min:0',
                    'max:999999999.99',
                    'regex:/^\d{1,9}(\.\d{1,2})?$/'
                ],
                'opening_date' => ['nullable', 'date', 'required_with:opening_balance'],
            ]);

            // Normalize opening balance: round to 2 decimal places and ensure it's within valid range
            $openingBalance = isset($validated['opening_balance']) 
                ? round((float)$validated['opening_balance'], 2) 
                : null;
            $openingDate = $validated['opening_date'] ?? null;
            
            // Validate normalized opening balance is still within range
            if ($openingBalance !== null && ($openingBalance < 0 || $openingBalance > 999999999.99)) {
                throw ValidationException::withMessages([
                    'opening_balance' => ['Opening balance must be between 0 and 999,999,999.99']
                ]);
            }
            
            // Remove opening fields from owner data
            unset($validated['opening_balance'], $validated['opening_date']);

            // Wrap everything in a database transaction
            $owner = DB::transaction(function () use ($validated, $openingBalance, $openingDate) {
                // Auto-generate owner_code based on owner_type
                $ownerCode = $this->generateOwnerCode($validated['owner_type']);
                $validated['owner_code'] = $ownerCode;
                
                $validated['status'] = 'ACTIVE'; // Schema uses uppercase
                $validated['is_system'] = false; // User-created owners are never system-generated
                $validated['created_by'] = auth()->id(); // Set the authenticated user as creator
                
                // Normalize phone: accept either phone or phone_number
                if (isset($validated['phone_number'])) {
                    $validated['phone'] = $validated['phone_number'];
                }
                unset($validated['phone_number']);

                // Step 1: Create the owner
                $owner = Owner::create($validated);

                // Step 2: Create opening transaction if opening balance > 0
                if ($openingBalance && $openingBalance > 0) {
                    $this->createOpeningTransaction($owner->id, $openingBalance, $openingDate);
                }

                return $owner;
            });

            // Log activity
            $user = auth()->user();
            if ($user) {
                $this->logOwnerActivity($request, $user, $owner, 'SUCCESS');
            }

            return response()->json([
                'success' => true,
                'message' => 'Owner created successfully',
                'data'  => $owner
            ], 201);
            } 
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ], 500);
        }

    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        try {
        $owner = Owner::find($id);

        if (!$owner) {
            return response()->json([
                'success' => false,
                'message' => 'Owner not found',
                'data' => null
            ], 404);
        }

        if ($owner->status === 'archived') {
            return response()->json([
                'success' => false,
                'message' => 'Owner is archived',
                'data' => null
            ], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Owner retrieved successfully',
            'data' => $owner
        ]);
        }
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Something went wrong',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        try {
            //LANCE to! - Find the Owner id first
            $owner = Owner::find($id);

            //If Owner doesnt exist  display Unauntenticated
            if (!$owner) {
                return response()->json([
                    'success'=> false,
                    'message' =>'Owner not found',
                    'data' => null,
                ], 404);
            }

            if ($owner->status === 'archived') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot update archived owner',
                    'data' => null
                ], 403);
            }

            //Validate data (SYSTEM allowed for update only - when editing existing system owners)
            $validated = $request->validate([
                'owner_type' => ['required', 'string', 'max:30', 'in:COMPANY,CLIENT,MAIN,SYSTEM'],
                'name' => ['required', 'string', 'min:2', 'unique:owners,name,' . $owner->id],
                'description' => ['nullable', 'string'],
                'email' => ['nullable', 'email', 'unique:owners,email,' . $owner->id],
                'phone' => ['nullable', 'string', 'max:100'],
                'phone_number' => ['nullable', 'string', 'max:100'],
                'address' => ['nullable', 'string'],
                'status' => ['sometimes', 'required', 'string', 'in:ACTIVE,INACTIVE,SUSPENDED,active,inactive,suspended']
            ]);

            // Normalize phone: accept either phone or phone_number
            if (isset($validated['phone_number'])) {
                $validated['phone'] = $validated['phone_number'];
            }
            unset($validated['phone_number']);

            // Normalize status to uppercase
            if (isset($validated['status'])) {
                $validated['status'] = strtoupper($validated['status']);
            }

            //Update data
            $owner->update($validated);

            //Return success response
            return response()->json([
                'success' => true,
                'message' => 'Owner Details Successfully updated',
                'data' => $owner
            ]);
        }
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ], 500);
        }

    }

    public function inactive($id) 
    {
        try {
            //Find the owner user id
            $owner = Owner::find($id);

            if (!$owner) {
                return response()->json([
                    'error' => 'Owner not found'
                ], 404);
            }

            //UPDATE!
            if ($owner->status === 'INACTIVE' || $owner->status === 'inactive') {
                return response()->json(['error' => 'Owner already inactive'], 400);
            }

            $owner->update(['status' => 'INACTIVE']);

            return response()->json([
                'success' => true,
                'message' => 'Owner successfully archived',
                'data' => $owner
            ]);
        }
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ], 500);
        }
    }

    public function restore($id) 
    {
        try {
            $owner = Owner::find($id);

            if (!$owner) {
                return response()->json([
                    'error' => 'Owner not found'
                ], 404);
            }

            if ($owner->status === 'ACTIVE' || $owner->status === 'active') {
                return response()->json(['error' => 'Owner already active'], 400);
            }

            $owner->status = 'ACTIVE';
            $owner->save();

            return response()->json([
                'success' => true,
                'message' => 'Owner successfully restored',
                'data' => $owner
            ]);
        }
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Something went wrong',
                'data' => null
            ], 500);
        }
    }

    /**
     * Create opening transaction for a new owner
     */
    private function createOpeningTransaction(int $newOwnerId, float $openingBalance, ?string $openingDate = null): void
    {
        // Get SYSTEM owner
        $systemOwner = Owner::where('owner_code', 'SYS-000')
            ->orWhere(function($q) {
                $q->where('owner_type', 'SYSTEM')
                  ->where('is_system', true);
            })
            ->first();

        if (!$systemOwner) {
            throw new \Exception('SYSTEM owner not found. Please seed the SYSTEM owner first.');
        }

        // Generate voucher number for opening transaction: OPN-YY-000000 (2-digit year)
        $year = date('y'); // 2-digit year (e.g., 26 for 2026)
        
        // Find the latest opening transaction voucher for this year
        // Support both old format (OPN-2026-...) and new format (OPN-26-...)
        $year4Digit = date('Y');
        $latestOpeningTransaction = Transaction::where(function($q) use ($year, $year4Digit) {
                $q->where('voucher_no', 'like', "OPN-{$year}-%")
                  ->orWhere('voucher_no', 'like', "OPN-{$year4Digit}-%");
            })
            ->orderByRaw('CAST(SUBSTRING(voucher_no, LOCATE("-", voucher_no, 5) + 1) AS UNSIGNED) DESC')
            ->first();
        
        $nextNumber = 1;
        if ($latestOpeningTransaction && $latestOpeningTransaction->voucher_no) {
            // Extract the number part after "OPN-YY-" or "OPN-YYYY-"
            $parts = explode('-', $latestOpeningTransaction->voucher_no);
            if (count($parts) === 3 && is_numeric($parts[2])) {
                $nextNumber = (int)$parts[2] + 1;
            }
        }
        
        $voucherNo = sprintf('OPN-%s-%06d', $year, $nextNumber);
        
        // Use opening date or today
        $voucherDate = $openingDate ? date('Y-m-d', strtotime($openingDate)) : date('Y-m-d');

        // Normalize opening balance: ensure it's rounded to 2 decimal places
        $normalizedBalance = round((float)$openingBalance, 2);

        // Create opening transaction
        // Note: Opening transactions use trans_method = 'TRANSFER' and SYSTEM owner to identify them
        $transaction = Transaction::create([
            'voucher_no' => $voucherNo,
            'voucher_date' => $voucherDate,
            'trans_method' => 'TRANSFER',
            'trans_type' => 'OPENING',
            'from_owner_id' => $systemOwner->id,
            'to_owner_id' => $newOwnerId,
            'amount' => $normalizedBalance,
            'particulars' => 'Opening Balance',
            'created_by' => auth()->id(),
        ]);

        // Post the transaction immediately (create ledger entries)
        $this->postTransaction($transaction);
    }

    /**
     * Post a transaction - create ledger entries and compute running balances
     */
    private function postTransaction(Transaction $transaction): void
    {
        // Load relationships
        $transaction->load(['fromOwner', 'toOwner']);
        
        $fromOwner = $transaction->fromOwner;
        $toOwner = $transaction->toOwner;

        if (!$fromOwner || !$toOwner) {
            throw new \Exception('Invalid transaction: owner not found');
        }

        $amount = (float) $transaction->amount;
        $voucherDate = $transaction->voucher_date ? $transaction->voucher_date->format('Y-m-d') : null;

        // Get instrument numbers
        $instrumentNos = $transaction->instruments()
            ->pluck('instrument_no')
            ->filter()
            ->implode(', ') ?: null;

        // Build particulars
        $particulars = $transaction->particulars ?? '';
        if ($transaction->unit_id) {
            $unit = $transaction->unit()->first();
            if ($unit) {
                $particulars = ($unit->unit_name ?? '') . ' - ' . $particulars;
            }
        }

        // Get current balances for both owners
        // IMPORTANT: Order by created_at (not voucher_date) to get the most recent entry chronologically
        // This ensures running balance is calculated correctly even if transactions are created out of voucher_date order
        $fromLastEntry = OwnerLedgerEntry::where('owner_id', $transaction->from_owner_id)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();
        
        $toLastEntry = OwnerLedgerEntry::where('owner_id', $transaction->to_owner_id)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        // Determine transaction type from trans_method and owner types
        // Opening transactions: trans_method = 'TRANSFER' and from_owner is SYSTEM
        $isOpening = $transaction->trans_method === 'TRANSFER' && 
                     $fromOwner && 
                     $fromOwner->owner_type === 'SYSTEM';
        $isDeposit = $transaction->trans_method === 'DEPOSIT';
        $isWithdrawal = $transaction->trans_method === 'WITHDRAWAL';

        // Trust Account Model Logic
        // Opening Balance: Should show as DEPOSIT for the receiving owner (all types)
        // - MAIN: deposit = debit (increase)
        // - CLIENT: deposit = credit (increase)
        
        $fromType = $fromOwner->owner_type ?? null;
        $toType = $toOwner->owner_type ?? null;
        
        $fromPrevBalance = $fromLastEntry ? (float) $fromLastEntry->running_balance : 0;
        $toPrevBalance = $toLastEntry ? (float) $toLastEntry->running_balance : 0;

        // For opening transactions: SYSTEM shows DEPOSIT (money coming in), new owner shows deposit
        // Opening balance represents initial capital/funds entering the system
        if ($isOpening) {
            // SYSTEM (from): Should show as DEPOSIT (increase) - opening balance is money coming INTO the system
            if ($fromType === 'SYSTEM') {
                // SYSTEM: deposit = debit (increase) - opening balance increases SYSTEM balance
                $fromDebit = $amount;
                $fromCredit = 0;
                $fromNewBalance = $fromPrevBalance + $amount; // Increase
            } else if ($fromType === 'MAIN') {
                // MAIN: deposit = debit (increase)
                $fromDebit = $amount;
                $fromCredit = 0;
                $fromNewBalance = $fromPrevBalance + $amount; // Increase
            } else {
                // CLIENT or other: deposit = credit (increase)
                $fromDebit = 0;
                $fromCredit = $amount;
                $fromNewBalance = $fromPrevBalance + $amount; // Increase
            }

            // New Owner (to): deposit for ALL types
            if ($toType === 'MAIN') {
                // MAIN (Asset): deposit = debit (increase)
                $toDebit = $amount;
                $toCredit = 0;
                $toNewBalance = $toPrevBalance + $amount; // Increase
            } else {
                // CLIENT/COMPANY (Liability): deposit = credit (increase)
                $toDebit = 0;
                $toCredit = $amount;
                $toNewBalance = $toPrevBalance + $amount; // Increase
            }
        } else {
            // For other transaction types, use the trust account model from TransactionController
            // MAIN (Asset): deposit = debit, withdrawal = credit
            // CLIENT/COMPANY (Liability): deposit = credit, withdrawal = debit
            $isAssetFrom = $fromType === 'MAIN';
            $isAssetTo = $toType === 'MAIN';

            if ($isAssetFrom) {
                if ($isDeposit) {
                    $fromDebit = $amount;
                    $fromCredit = 0;
                    $fromNewBalance = $fromPrevBalance + $amount;
                } else {
                    $fromDebit = 0;
                    $fromCredit = $amount;
                    $fromNewBalance = $fromPrevBalance - $amount;
                }
            } else {
                if ($isDeposit) {
                    $fromDebit = 0;
                    $fromCredit = $amount;
                    $fromNewBalance = $fromPrevBalance + $amount;
                } else {
                    $fromDebit = $amount;
                    $fromCredit = 0;
                    $fromNewBalance = $fromPrevBalance - $amount;
                }
            }

            if ($isAssetTo) {
                if ($isDeposit) {
                    $toDebit = $amount;
                    $toCredit = 0;
                    $toNewBalance = $toPrevBalance + $amount;
                } else {
                    $toDebit = 0;
                    $toCredit = $amount;
                    $toNewBalance = $toPrevBalance - $amount;
                }
            } else {
                if ($isDeposit) {
                    $toDebit = 0;
                    $toCredit = $amount;
                    $toNewBalance = $toPrevBalance + $amount;
                } else {
                    $toDebit = $amount;
                    $toCredit = 0;
                    $toNewBalance = $toPrevBalance - $amount;
                }
            }
        }

        // Create ledger entry for FROM owner
        OwnerLedgerEntry::create([
            'owner_id' => $transaction->from_owner_id,
            'transaction_id' => $transaction->id,
            'voucher_no' => $transaction->voucher_no ?? '—',
            'voucher_date' => $voucherDate,
            'instrument_no' => $instrumentNos,
            'debit' => $fromDebit,
            'credit' => $fromCredit,
            'running_balance' => $fromNewBalance,
            'unit_id' => $transaction->unit_id,
            'particulars' => $particulars,
            'transfer_group_id' => $transaction->transfer_group_id,
            'created_at' => now(),
        ]);

        // Create ledger entry for TO owner
        OwnerLedgerEntry::create([
            'owner_id' => $transaction->to_owner_id,
            'transaction_id' => $transaction->id,
            'voucher_no' => $transaction->voucher_no ?? '—',
            'voucher_date' => $voucherDate,
            'instrument_no' => $instrumentNos,
            'debit' => $toDebit,
            'credit' => $toCredit,
            'running_balance' => $toNewBalance,
            'unit_id' => $transaction->unit_id,
            'particulars' => $particulars,
            'transfer_group_id' => $transaction->transfer_group_id,
            'created_at' => now(),
        ]);

        // Transaction is automatically posted when ledger entries are created
        // Note: If is_posted and posted_at fields exist in future migrations, update here
    }

    /**
     * Generate owner code based on owner type
     * Format: CL-001, EMP-002, MAIN-001, CO-001
     */
    private function generateOwnerCode(string $ownerType): string
    {
        // Map owner types to prefixes
        $prefixes = [
            'CLIENT' => 'CL',
            'EMPLOYEE' => 'EMP',
            'MAIN' => 'MAIN',
            'COMPANY' => 'CO',
            'SYSTEM' => 'SYS',
        ];

        $prefix = $prefixes[$ownerType] ?? 'OWN';
        
        // Get the highest number for this prefix
        $lastOwner = Owner::where('owner_code', 'like', $prefix . '-%')
            ->orderByRaw('CAST(SUBSTRING(owner_code, ' . (strlen($prefix) + 2) . ') AS UNSIGNED) DESC')
            ->first();

        if ($lastOwner && preg_match('/-(\d+)$/', $lastOwner->owner_code, $matches)) {
            $nextNumber = intval($matches[1]) + 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('%s-%03d', $prefix, $nextNumber);
    }

    /**
     * Log owner activity to activity_logs table.
     *
     * @param Request $request
     * @param \App\Models\User $user
     * @param Owner $owner
     * @param string $status
     * @return void
     */
    protected function logOwnerActivity(Request $request, $user, Owner $owner, string $status = 'SUCCESS'): void
    {
        try {
            // Get IP address
            $ipAddress = $request->ip() ?? $request->header('X-Forwarded-For') ?? $request->header('X-Real-IP') ?? null;
            
            // Get user agent
            $userAgent = $request->userAgent() ?? $request->header('User-Agent') ?? null;
            
            // Build title
            $title = 'Owner Created';
            
            // Build description
            $description = "Created {$owner->owner_type} owner: {$owner->name}";
            if ($owner->owner_code) {
                $description .= " ({$owner->owner_code})";
            }
            if ($owner->email) {
                $description .= " • Email: {$owner->email}";
            }
            if ($owner->phone) {
                $description .= " • Phone: {$owner->phone}";
            }
            if ($owner->description) {
                $description .= " • Description: {$owner->description}";
            }
            
            // Build metadata
            $metadata = [
                'owner_id' => $owner->id,
                'owner_code' => $owner->owner_code,
                'owner_type' => $owner->owner_type,
                'name' => $owner->name,
                'email' => $owner->email,
                'phone' => $owner->phone,
                'address' => $owner->address,
                'description' => $owner->description,
                'status' => $owner->status,
                'is_system' => $owner->is_system,
            ];
            
            // Create activity log entry
            $log = ActivityLog::create([
                'activity_type' => 'OWNER',
                'action' => 'CREATE',
                'status' => $status,
                'title' => $title,
                'description' => $description,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'user_email' => $user->email,
                'target_id' => $owner->id,
                'target_type' => Owner::class,
                'metadata' => $metadata,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'created_at' => now(),
            ]);
            
            Log::info('Activity log created successfully', [
                'log_id' => $log->id,
                'activity_type' => 'OWNER',
                'owner_id' => $owner->id,
            ]);
        } catch (\Exception $e) {
            // Don't fail owner creation if activity logging fails
            Log::error('Failed to log owner activity', [
                'owner_id' => $owner->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
