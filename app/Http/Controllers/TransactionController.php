<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\TransactionInstrument;
use App\Models\TransactionAttachment;
use App\Models\OwnerLedgerEntry;
use App\Models\Owner;
use App\Models\Unit;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Services\FirebaseStorageService;
use App\Jobs\UploadTransactionAttachmentToFirebase;
use App\Http\Controllers\OwnerController;
use Exception;

class TransactionController extends Controller
{
    // File upload constants
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
    /**
     * Create a deposit transaction.
     */
    public function storeDeposit(Request $request)
    {
        return $this->storeTransaction($request, 'DEPOSIT');
    }

    /**
     * Create a withdrawal transaction.
     */
    public function storeWithdrawal(Request $request)
    {
        return $this->storeTransaction($request, 'WITHDRAWAL');
    }

    /**
     * Create an opening balance transaction (SYSTEM → owner or unit).
     * Used for owner/unit opening balance when "Opening balance (System transaction)" is checked.
     */
    public function storeOpening(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        $userRole = $user->role ?? '';
        if (!in_array(strtolower($userRole), ['accountant', 'super_admin', 'admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permissions. Only accountants and admins can create transactions.'
            ], 403);
        }

        $validated = $request->validate([
            'to_owner_id' => ['required', 'integer', 'exists:owners,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'particulars' => ['required', 'string', 'min:1'],
            'voucher_date' => ['nullable', 'date'],
        ]);

        $toOwner = Owner::find($validated['to_owner_id']);
        if (!$toOwner || !in_array($toOwner->owner_type, ['CLIENT', 'COMPANY'])) {
            return response()->json([
                'success' => false,
                'message' => 'To owner must be CLIENT or COMPANY for opening balance.',
                'errors' => ['to_owner_id' => ['Opening balance can only be assigned to clients or companies.']]
            ], 422);
        }

        if ($toOwner->status !== 'ACTIVE') {
            return response()->json([
                'success' => false,
                'message' => 'To owner must be ACTIVE.',
                'errors' => ['to_owner_id' => ['Owner must be ACTIVE.']]
            ], 422);
        }

        if (!empty($validated['unit_id'])) {
            $unit = Unit::find($validated['unit_id']);
            if (!$unit || $unit->owner_id != $validated['to_owner_id']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unit does not belong to the selected owner.',
                    'errors' => ['unit_id' => ['Unit does not belong to the selected owner.']]
                ], 422);
            }
        }

        // Opening balance not allowed if owner or unit already has transactions
        $unitId = $validated['unit_id'] ?? null;
        $ownerGeneralHasEntry = OwnerLedgerEntry::where('owner_id', $validated['to_owner_id'])
            ->whereNull('unit_id')
            ->exists();
        if ($ownerGeneralHasEntry && !$unitId) {
            return response()->json([
                'success' => false,
                'message' => 'Owner already has transactions. Opening balance is not allowed.',
                'errors' => ['to_owner_id' => ['This owner already has transactions. Opening balance can only be set for new owners.']]
            ], 422);
        }
        if ($unitId) {
            $unitHasEntry = OwnerLedgerEntry::where('owner_id', $validated['to_owner_id'])
                ->where('unit_id', $unitId)
                ->exists();
            if ($unitHasEntry) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unit already has transactions. Opening balance is not allowed.',
                    'errors' => ['unit_id' => ['This unit already has transactions. Opening balance can only be set for units with no transactions.']]
                ], 422);
            }
        }

        $systemOwner = Owner::where('owner_type', 'SYSTEM')
            ->where('is_system', true)
            ->first();

        if (!$systemOwner) {
            return response()->json([
                'success' => false,
                'message' => 'SYSTEM owner not found. Please seed the SYSTEM owner first.'
            ], 500);
        }

        $amount = round((float) $validated['amount'], 2);
        $voucherDate = !empty($validated['voucher_date']) ? $validated['voucher_date'] : date('Y-m-d');

        DB::beginTransaction();
        try {
            $transaction = Transaction::create([
                'voucher_no' => null,
                'voucher_date' => $voucherDate,
                'trans_method' => 'TRANSFER',
                'trans_type' => 'OPENING',
                'from_owner_id' => $systemOwner->id,
                'to_owner_id' => $validated['to_owner_id'],
                'unit_id' => $validated['unit_id'] ?? null,
                'amount' => $amount,
                'particulars' => $validated['particulars'],
                'created_by' => $user->id,
                'status' => 'ACTIVE',
                'is_posted' => false,
            ]);

            app(OwnerController::class)->postTransaction($transaction);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Opening balance created successfully.',
                'data' => $transaction->load(['fromOwner', 'toOwner', 'unit']),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Opening transaction failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a transaction (deposit or withdrawal) with instruments and attachments.
     * 🔥 CRITICAL: Everything wrapped in DB transaction for data integrity.
     */
    protected function storeTransaction(Request $request, string $transMethod)
    {
        // 🔥 8️⃣ Security: Validate user role
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        // Check if user has accountant or super_admin role
        $userRole = $user->role ?? '';
        if (!in_array(strtolower($userRole), ['accountant', 'super_admin', 'admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permissions. Only accountants and admins can create transactions.'
            ], 403);
        }

        try {
            // Validate request structure
            $request->validate([
                'transaction' => ['required', 'string'],
                'instruments' => ['nullable', 'string'],
                'attachments' => ['nullable', 'string'],
                'voucher' => ['nullable', 'file', 'mimes:jpeg,jpg,png,pdf', 'mimetypes:image/jpeg,image/jpg,image/png,image/x-png,application/pdf', 'max:10240'], // 10MB, images and PDFs only
            ]);

            $transactionData = json_decode($request->transaction, true);
            $instrumentsData = $request->filled('instruments') ? json_decode($request->instruments, true) : [];
            $attachmentsData = $request->filled('attachments') ? json_decode($request->attachments, true) : [];

            if (!is_array($transactionData)) {
                throw ValidationException::withMessages([
                    'transaction' => ['Invalid transaction data format']
                ]);
            }

            // 🔥 2️⃣ Comprehensive server-side validation
            $validated = $this->validateTransactionData($transactionData, $transMethod);

            // 🔥 7️⃣ Prevent duplicate submission (check unique voucher_no)
            if (!empty($validated['voucher_no'])) {
                $existingTransaction = Transaction::where('voucher_no', $validated['voucher_no'])->first();
                if ($existingTransaction) {
                    throw ValidationException::withMessages([
                        'voucher_no' => ['Voucher number already exists. Please use a unique voucher number.']
                    ]);
                }
            }

            // 🔥 1️⃣ Wrap EVERYTHING in DB transaction
            $transaction = null;
            $errorOccurred = false;
            $errorMessage = '';

            DB::beginTransaction();
            try {
                // 🔥 🔟 Enforce voucher mode logic (backend-controlled)
                $hasVoucher = !empty($validated['voucher_no']);
                $hasVoucherDate = !empty($validated['voucher_date']);
                
                // Enforce: if voucher_no exists, voucher_date must exist
                if ($hasVoucher && !$hasVoucherDate) {
                    throw ValidationException::withMessages([
                        'voucher_date' => ['Voucher date is required when voucher number is provided']
                    ]);
                }

                // 🔥 5️⃣ Create transaction with is_posted = false initially
                $transaction = Transaction::create([
                    'voucher_no' => $validated['voucher_no'] ?? null,
                    'voucher_date' => $validated['voucher_date'] ?? null,
                    'trans_method' => $transMethod,
                    'trans_type' => $validated['trans_type'],
                    'from_owner_id' => $validated['from_owner_id'],
                    'to_owner_id' => $validated['to_owner_id'],
                    'unit_id' => $validated['unit_id'] ?? null,
                    'amount' => $validated['amount'],
                    'fund_reference' => $validated['fund_reference'] ?? null,
                    'particulars' => $validated['particulars'] ?? null,
                    'transfer_group_id' => $validated['transfer_group_id'] ?? null,
                    'person_in_charge' => $validated['person_in_charge'] ?? null,
                    'status' => 'ACTIVE',
                    'is_posted' => false, // 🔥 5️⃣ Start as unposted
                    'created_by' => $user->id, // 🔥 8️⃣ Get from session, not frontend
                ]);

                // 🔥 3️⃣ Validate and create instruments (do NOT trust frontend)
                if (is_array($instrumentsData) && count($instrumentsData) > 0) {
                    $seenInstrumentNos = [];
                    foreach ($instrumentsData as $inst) {
                        // Validate instrument_type (only CHEQUE and DEPOSIT SLIP have instrument numbers)
                        $instrumentType = $inst['instrument_type'] ?? $validated['trans_type'];
                        if (!in_array($instrumentType, ['CHEQUE', 'DEPOSIT SLIP'])) {
                            throw ValidationException::withMessages([
                                'instruments' => ["Invalid instrument type: {$instrumentType}. Only CHEQUE and DEPOSIT SLIP support instrument numbers."]
                            ]);
                        }

                        // Validate instrument_no if provided
                        $instrumentNo = isset($inst['instrument_no']) ? trim($inst['instrument_no']) : null;
                        if ($instrumentNo !== null && $instrumentNo !== '') {
                            // Validate length and format
                            if (strlen($instrumentNo) > 255) {
                                throw ValidationException::withMessages([
                                    'instruments' => ['Instrument number exceeds maximum length']
                                ]);
                            }

                            // Prevent duplicate instrument_no within same transaction
                            if (in_array($instrumentNo, $seenInstrumentNos)) {
                                throw ValidationException::withMessages([
                                    'instruments' => ["Duplicate instrument number: {$instrumentNo}"]
                                ]);
                            }
                            $seenInstrumentNos[] = $instrumentNo;
                        }

                        // Only create if instrument_no is not empty
                        if ($instrumentNo !== null && $instrumentNo !== '') {
                            $transaction->instruments()->create([
                                'instrument_type' => $instrumentType,
                                'instrument_no' => $instrumentNo,
                                'notes' => isset($inst['notes']) ? trim($inst['notes']) : null,
                            ]);
                        }
                    }
                }

                // 🔥 6️⃣ Handle file uploads with proper validation and unique naming (Firebase Storage)
                $this->handleTransactionFileUploads($request, $transaction);

                // 🔥 4️⃣ Generate owner_ledger_entries (backend-calculated)
                $transaction->load(['instruments', 'unit', 'fromOwner', 'toOwner']);
                $saveToUnitLedger = $validated['save_to_unit_ledger'] ?? false;
                $this->createLedgerEntries($transaction, $saveToUnitLedger);

                // 🔥 5️⃣ Mark transaction as posted only after everything succeeds
                $transaction->update([
                    'is_posted' => true,
                    'posted_at' => now(),
                ]);

                DB::commit();

                // 🔥 8️⃣ Log activity (audit trail) - AFTER transaction commits
                // Reload transaction with relationships for logging
                $transaction->load(['instruments', 'attachments', 'fromOwner', 'toOwner', 'unit']);
                $this->logTransactionActivity($request, $user, $transaction, $transMethod, 'SUCCESS');
            } catch (ValidationException $e) {
                DB::rollBack();
                throw $e;
            } catch (Exception $e) {
                DB::rollBack();
                $errorOccurred = true;
                $errorMessage = $e->getMessage();
                
                // Log error
                Log::error('Transaction creation failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                
                throw $e;
            }
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create transaction: ' . $e->getMessage()
            ], 500);
        }

        $transaction->load(['instruments', 'attachments', 'fromOwner', 'toOwner', 'unit']);

        return response()->json([
            'success' => true,
            'message' => $transMethod === 'DEPOSIT' ? 'Deposit created successfully' : 'Withdrawal created successfully',
            'data' => $transaction
        ], 201);
    }

    /**
     * 🔥 4️⃣ Create owner_ledger_entries for a transaction.
     * Backend-calculated running balances - NEVER trust frontend math.
     * 
     * Trust Account Model: Company tracks money it holds for different owners.
     * - DEPOSIT: Both MAIN and CLIENT show deposit (both balances increase)
     * - WITHDRAWAL: Both MAIN and CLIENT show withdrawal (both balances decrease)
     * 
     * This is NOT a transfer model - it's a trust/wallet/escrow model.
     */
    protected function createLedgerEntries(Transaction $transaction, bool $saveToUnitLedger = false): void
    {
        $amount = (float) $transaction->amount;
        $voucherNo = $transaction->voucher_no ?? '—';
        $voucherDate = $transaction->voucher_date;
        $particulars = $transaction->particulars ?? '';
        $transactionUnitId = $transaction->unit_id; // Unit ID from transaction (for display in particulars)
        // Only use unit_id in ledger entry if saveToUnitLedger is true
        $ledgerUnitId = $saveToUnitLedger ? $transactionUnitId : null;
        $transferGroupId = $transaction->transfer_group_id ? (string) $transaction->transfer_group_id : null;

        $instrumentNos = $transaction->instruments->pluck('instrument_no')->filter()->values()->all();
        $instrumentNo = implode(', ', $instrumentNos) ?: null;

        $particularsWithUnit = $particulars;
        if ($transaction->unit && $transaction->unit->unit_name) {
            $particularsWithUnit = $transaction->unit->unit_name . ' - ' . $particulars;
        }

        // Load owners to determine their types
        $fromOwner = $transaction->fromOwner;
        $toOwner = $transaction->toOwner;

        if (!$fromOwner || !$toOwner) {
            throw new Exception('Invalid owner IDs: owners must exist');
        }

        // Determine transaction type from trans_method
        $isDeposit = $transaction->trans_method === 'DEPOSIT';
        $isWithdrawal = $transaction->trans_method === 'WITHDRAWAL';

        // 🔥 Trust Account Model Logic
        // For DEPOSIT: Both MAIN and CLIENT show deposit (both balances increase)
        // For WITHDRAWAL: Both MAIN and CLIENT show withdrawal (both balances decrease)
        
        // MAIN (Asset): deposit = debit (increase), withdrawal = credit (decrease)
        // CLIENT (Liability): deposit = credit (increase), withdrawal = debit (decrease)

        // From Owner Entry
        // From owner (MAIN/SYSTEM) always uses unit_id=null - unit is a recipient concept.
        // Filter by unit_id to get the correct running balance for this ledger stream.
        $prevFromEntry = OwnerLedgerEntry::where('owner_id', $transaction->from_owner_id)
            ->whereNull('unit_id')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();
        
        $prevFromBalance = $prevFromEntry ? (float) $prevFromEntry->running_balance : 0;

        $isAssetFrom = $fromOwner->owner_type === 'MAIN';
        if ($isAssetFrom) {
            // MAIN (Asset): deposit = debit (increase), withdrawal = credit (decrease)
            if ($isDeposit) {
                $fromDebit = $amount;
                $fromCredit = 0;
                $newFromBalance = $prevFromBalance + $amount; // Increase
            } else {
                $fromDebit = 0;
                $fromCredit = $amount;
                $newFromBalance = $prevFromBalance - $amount; // Decrease
            }
        } else {
            // CLIENT (Liability): deposit = credit (increase), withdrawal = debit (decrease)
            if ($isDeposit) {
                $fromDebit = 0;
                $fromCredit = $amount;
                $newFromBalance = $prevFromBalance + $amount; // Increase
            } else {
                $fromDebit = $amount;
                $fromCredit = 0;
                $newFromBalance = $prevFromBalance - $amount; // Decrease
            }
        }

        OwnerLedgerEntry::create([
            'owner_id' => $transaction->from_owner_id,
            'transaction_id' => $transaction->id,
            'voucher_no' => $voucherNo,
            'voucher_date' => $voucherDate,
            'instrument_no' => $instrumentNo,
            'debit' => $fromDebit,
            'credit' => $fromCredit,
            'running_balance' => $newFromBalance,
            'unit_id' => null, // Main never tracks by unit; unit is recipient concept
            'particulars' => $particularsWithUnit,
            'transfer_group_id' => $transferGroupId,
            'created_at' => now(),
        ]);

        // To Owner Entry
        // CRITICAL: Filter by unit_id so each unit's ledger has its own running balance.
        // When owner has many units, mixing unit_id would corrupt running balances.
        $prevToQuery = OwnerLedgerEntry::where('owner_id', $transaction->to_owner_id);
        if ($ledgerUnitId) {
            $prevToQuery->where('unit_id', $ledgerUnitId);
        } else {
            $prevToQuery->whereNull('unit_id');
        }
        $prevToEntry = $prevToQuery
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();
        
        $prevToBalance = $prevToEntry ? (float) $prevToEntry->running_balance : 0;

        $isAssetTo = $toOwner->owner_type === 'MAIN';
        if ($isAssetTo) {
            // MAIN (Asset): deposit = debit (increase), withdrawal = credit (decrease)
            if ($isDeposit) {
                $toDebit = $amount;
                $toCredit = 0;
                $newToBalance = $prevToBalance + $amount; // Increase
            } else {
                $toDebit = 0;
                $toCredit = $amount;
                $newToBalance = $prevToBalance - $amount; // Decrease
            }
        } else {
            // CLIENT (Liability): deposit = credit (increase), withdrawal = debit (decrease)
            if ($isDeposit) {
                $toDebit = 0;
                $toCredit = $amount;
                $newToBalance = $prevToBalance + $amount; // Increase
            } else {
                $toDebit = $amount;
                $toCredit = 0;
                $newToBalance = $prevToBalance - $amount; // Decrease
            }
        }

        OwnerLedgerEntry::create([
            'owner_id' => $transaction->to_owner_id,
            'transaction_id' => $transaction->id,
            'voucher_no' => $voucherNo,
            'voucher_date' => $voucherDate,
            'instrument_no' => $instrumentNo,
            'debit' => $toDebit,
            'credit' => $toCredit,
            'running_balance' => $newToBalance,
            'unit_id' => $ledgerUnitId, // Only set if saveToUnitLedger is true, otherwise null (general ledger)
            'particulars' => $particularsWithUnit, // Always includes unit name if transaction has unit_id
            'transfer_group_id' => $transferGroupId,
            'created_at' => now(),
        ]);
    }

    /**
     * 🔥 2️⃣ Comprehensive server-side validation.
     * Never trust frontend - validate everything here.
     */
    protected function validateTransactionData(array $data, string $transMethod): array
    {
        // Clean and validate amount first
        if (isset($data['amount'])) {
            // Remove commas, spaces, and any non-numeric characters except decimal point
            $amountStr = is_string($data['amount']) ? $data['amount'] : (string) $data['amount'];
            $amountStr = preg_replace('/[^\d.]/', '', $amountStr);
            
            // Ensure only one decimal point
            $parts = explode('.', $amountStr);
            if (count($parts) > 2) {
                $amountStr = $parts[0] . '.' . implode('', array_slice($parts, 1));
            }
            
            // Convert to float and validate
            $amount = (float) $amountStr;
            
            // Validate realistic amount range (0.01 to 999,999,999.99)
            if ($amount < 0.01 || $amount > 999999999.99) {
                throw ValidationException::withMessages([
                    'amount' => ['Amount must be between ₱0.01 and ₱999,999,999.99']
                ]);
            }
            
            // Round to 2 decimal places
            $data['amount'] = round($amount, 2);
        }
        
        // 🔥 2️⃣ Basic validation rules
        $rules = [
            'trans_type' => ['required', 'in:CHEQUE,DEPOSIT SLIP,CASH DEPOSIT,CHEQUE DEPOSIT,BANK TRANSFER'],
            'from_owner_id' => ['required', 'integer', 'exists:owners,id'],
            'to_owner_id' => ['required', 'integer', 'exists:owners,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'], // Realistic range
            'fund_reference' => ['nullable', 'string', 'max:255'],
            'particulars' => ['required', 'string', 'min:1'], // 🔥 2️⃣ Required, not empty
            'person_in_charge' => ['nullable', 'string', 'max:255'],
            'voucher_no' => ['nullable', 'string', 'max:100'],
            'voucher_date' => ['nullable', 'date'],
            'transfer_group_id' => ['nullable', 'integer'],
            'save_to_unit_ledger' => ['nullable', 'boolean'], // Flag to determine if entry goes to unit-specific ledger
        ];

        $validated = validator($data, $rules)->validate();
        
        // Ensure amount is properly formatted as float with 2 decimal places
        $validated['amount'] = round((float) $validated['amount'], 2);

        // 🔥 2️⃣ Business rule: from_owner_id ≠ to_owner_id
        if ($validated['from_owner_id'] === $validated['to_owner_id']) {
            throw ValidationException::withMessages([
                'to_owner_id' => ['From owner and To owner cannot be the same']
            ]);
        }

        // 🔥 2️⃣ Validate owners exist and are ACTIVE
        $fromOwner = Owner::find($validated['from_owner_id']);
        $toOwner = Owner::find($validated['to_owner_id']);

        if (!$fromOwner) {
            throw ValidationException::withMessages([
                'from_owner_id' => ['From owner not found']
            ]);
        }

        if (!$toOwner) {
            throw ValidationException::withMessages([
                'to_owner_id' => ['To owner not found']
            ]);
        }

        if ($fromOwner->status !== 'ACTIVE') {
            throw ValidationException::withMessages([
                'from_owner_id' => ['From owner must be ACTIVE']
            ]);
        }

        if ($toOwner->status !== 'ACTIVE') {
            throw ValidationException::withMessages([
                'to_owner_id' => ['To owner must be ACTIVE']
            ]);
        }

        // 🔥 SYSTEM Owner Restriction: SYSTEM should only be used for OPENING, ADJUSTMENT, REVERSAL transactions
        // Never allow SYSTEM for normal DEPOSIT/WITHDRAWAL transactions
        $allowedCategoriesForSystem = ['OPENING', 'ADJUSTMENT', 'REVERSAL'];
        $isNormalTransaction = in_array($transMethod, ['DEPOSIT', 'WITHDRAWAL']);
        
        if ($isNormalTransaction) {
            if ($fromOwner->owner_type === 'SYSTEM') {
                throw ValidationException::withMessages([
                    'from_owner_id' => ['SYSTEM owner cannot be used for normal deposits or withdrawals. SYSTEM is only allowed for OPENING, ADJUSTMENT, or REVERSAL transactions.']
                ]);
            }
            
            if ($toOwner->owner_type === 'SYSTEM') {
                throw ValidationException::withMessages([
                    'to_owner_id' => ['SYSTEM owner cannot be used for normal deposits or withdrawals. SYSTEM is only allowed for OPENING, ADJUSTMENT, or REVERSAL transactions.']
                ]);
            }
        }

        // 🔥 2️⃣ Validate unit belongs to to_owner if provided
        if (!empty($validated['unit_id'])) {
            $unit = Unit::find($validated['unit_id']);
            if (!$unit) {
                throw ValidationException::withMessages([
                    'unit_id' => ['Unit not found']
                ]);
            }

            if ($unit->owner_id !== $validated['to_owner_id']) {
                throw ValidationException::withMessages([
                    'unit_id' => ['Unit does not belong to the selected To Owner']
                ]);
            }
        }

        // Amount is already cleaned and validated above, just ensure it's properly formatted
        $amount = (float) $validated['amount'];

        // 🔥 10️⃣ Enforce voucher mode logic
        $hasVoucherNo = !empty($validated['voucher_no']);
        $hasVoucherDate = !empty($validated['voucher_date']);

        // If voucher_no exists, voucher_date is required
        if ($hasVoucherNo && !$hasVoucherDate) {
            throw ValidationException::withMessages([
                'voucher_date' => ['Voucher date is required when voucher number is provided']
            ]);
        }

        // Normalize voucher_date
        if (!empty($validated['voucher_date'])) {
            $validated['voucher_date'] = $validated['voucher_date'];
        } else {
            $validated['voucher_date'] = null;
        }

        // Normalize voucher_no
        if (!empty($validated['voucher_no'])) {
            $validated['voucher_no'] = strtoupper(trim($validated['voucher_no']));
        } else {
            $validated['voucher_no'] = null;
        }

        return $validated;
    }

    /**
     * Get a transaction attachment file.
     */
    /**
     * Handle file uploads for a transaction (voucher and attachments).
     *
     * @param Request $request
     * @param Transaction $transaction
     * @return void
     */
    protected function handleTransactionFileUploads(Request $request, Transaction $transaction): void
    {
        $basePath = 'transactions/' . $transaction->id;
        $tempBasePath = 'temp/transactions/' . $transaction->id;

        // Handle voucher file - store to temp, queue for Firebase upload
        if ($request->hasFile('voucher')) {
            $file = $request->file('voucher');
            $this->validateAndQueueFileUpload($file, $basePath, $tempBasePath, 'voucher', 'voucher', $transaction);
        }

        // Handle attachment files (file_0, file_1, ...)
        $fileIndex = 0;
        while ($request->hasFile("file_{$fileIndex}")) {
            $file = $request->file("file_{$fileIndex}");
            $this->validateAndQueueFileUpload($file, $basePath, $tempBasePath, "file_{$fileIndex}", 'attachment', $transaction);
            $fileIndex++;
        }
    }

    /**
     * Validate file, store to temp disk, create attachment record, and dispatch job for Firebase upload.
     * This avoids blocking the request on slow Firebase uploads - transaction succeeds immediately.
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @param string $basePath Base path in Firebase Storage
     * @param string $tempBasePath Base path for temp storage
     * @param string $fieldName Field name for validation errors
     * @param string $filePrefix Prefix for generated filename (voucher or attachment)
     * @param Transaction $transaction
     * @return void
     */
    protected function validateAndQueueFileUpload(
        \Illuminate\Http\UploadedFile $file,
        string $basePath,
        string $tempBasePath,
        string $fieldName,
        string $filePrefix,
        Transaction $transaction
    ): void {
        // Validate file size
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw ValidationException::withMessages([
                $fieldName => ['File exceeds maximum size of 10MB']
            ]);
        }

        // Validate mime type
        $mimeType = $file->getMimeType();
        $extension = strtolower($file->getClientOriginalExtension());
        
        // Check MIME type and also allow by extension as fallback
        $isValidMimeType = in_array($mimeType, self::ALLOWED_MIME_TYPES);
        $isValidExtension = in_array($extension, ['jpeg', 'jpg', 'png', 'pdf']);
        
        // Also check if MIME type starts with image/ for PNG files (some systems return image/x-png)
        $isImageMimeType = str_starts_with($mimeType, 'image/') && $extension === 'png';
        
        if (!$isValidMimeType && !$isValidExtension && !$isImageMimeType) {
            throw ValidationException::withMessages([
                $fieldName => ['Invalid file type. Only JPEG, JPG, PNG, and PDF files are allowed.']
            ]);
        }

        // Generate unique file name
        $extension = $file->getClientOriginalExtension();
        $uniqueFileName = $filePrefix . '_' . Str::uuid() . '.' . $extension;
        $firebasePath = $basePath . '/' . $uniqueFileName;
        $tempPath = $tempBasePath . '/' . $uniqueFileName;

        // Store to temp disk (fast, local) - avoids blocking on Firebase
        Storage::disk('local')->put($tempPath, file_get_contents($file->getRealPath()));

        // Create attachment record with pending status - job will update when Firebase upload completes
        $attachment = $transaction->attachments()->create([
            'file_name' => $file->getClientOriginalName(),
            'file_type' => $mimeType,
            'file_path' => null,
            'upload_status' => 'pending',
            'temp_path' => $tempPath,
        ]);

        // Dispatch job for async Firebase upload - transaction succeeds immediately
        UploadTransactionAttachmentToFirebase::dispatch($attachment->id, $tempPath, $firebasePath, $mimeType);
    }

    public function getAttachment(Request $request, $transactionId, $attachmentId)
    {
        $transaction = Transaction::find($transactionId);
        
        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found'
            ], 404);
        }

        $attachment = TransactionAttachment::where('transaction_id', $transactionId)
            ->where('id', $attachmentId)
            ->first();

        if (!$attachment) {
            return response()->json([
                'success' => false,
                'message' => 'Attachment not found'
            ], 404);
        }

        // When upload is pending, serve from temp path (file is still uploading to Firebase)
        $filePath = $attachment->file_path;
        if (empty($filePath) && $attachment->upload_status === 'pending' && $attachment->temp_path) {
            $filePath = $attachment->temp_path;
        }

        // Check if file_path is a Firebase URL (starts with http/https)
        if ($filePath && (str_starts_with($filePath, 'http://') || str_starts_with($filePath, 'https://'))) {
            return redirect($filePath);
        }

        // Local/temp storage fallback
        if (!$filePath || !Storage::disk('local')->exists($filePath)) {
            return response()->json([
                'success' => false,
                'message' => $attachment->upload_status === 'pending'
                    ? 'File is still uploading. Please try again in a moment.'
                    : 'File not found'
            ], $attachment->upload_status === 'pending' ? 202 : 404);
        }

        $file = Storage::disk('local')->get($filePath);
        $mimeType = Storage::disk('local')->mimeType($filePath);

        return response($file, 200)
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', 'inline; filename="' . $attachment->file_name . '"');
    }

    /**
     * Check if file names already exist in transaction attachments.
     */
    public function checkDuplicateFileNames(Request $request)
    {
        $request->validate([
            'file_names' => 'required|array',
            'file_names.*' => 'required|string',
        ]);

        $fileNames = $request->input('file_names', []);
        $fileNamesWithoutExt = array_map(function ($fileName) {
            return pathinfo($fileName, PATHINFO_FILENAME);
        }, $fileNames);

        // Get all existing file names from transaction_attachments table
        $existingAttachments = TransactionAttachment::select('file_name')->get();
        $existingFileNamesWithoutExt = $existingAttachments->map(function ($attachment) {
            return pathinfo($attachment->file_name, PATHINFO_FILENAME);
        })->toArray();

        // Find duplicates by comparing file names without extension
        $duplicates = [];
        foreach ($fileNamesWithoutExt as $fileName) {
            if (in_array($fileName, $existingFileNamesWithoutExt)) {
                $duplicates[] = $fileName;
            }
        }

        return response()->json([
            'success' => true,
            'has_duplicates' => count($duplicates) > 0,
            'duplicates' => array_unique($duplicates),
        ]);
    }

    /**
     * Log transaction activity to activity_logs table.
     *
     * @param Request $request
     * @param \App\Models\User $user
     * @param Transaction $transaction
     * @param string $transMethod
     * @param string $status
     * @return void
     */
    protected function logTransactionActivity(Request $request, $user, Transaction $transaction, string $transMethod, string $status = 'SUCCESS'): void
    {
        try {
            // Get IP address
            $ipAddress = $request->ip() ?? $request->header('X-Forwarded-For') ?? $request->header('X-Real-IP') ?? null;
            
            // Get user agent
            $userAgent = $request->userAgent() ?? $request->header('User-Agent') ?? null;
            
            // Build title
            $title = ucfirst(strtolower($transMethod)) . ' Transaction Created';
            
            // Build description
            $fromOwnerName = $transaction->fromOwner->name ?? 'Unknown';
            $toOwnerName = $transaction->toOwner->name ?? 'Unknown';
            $voucherInfo = $transaction->voucher_no ? "Voucher #{$transaction->voucher_no}" : 'No Voucher';
            $amount = number_format($transaction->amount, 2);
            
            $description = "Created {$transMethod} transaction: {$voucherInfo} • Amount: ₱{$amount} • From: {$fromOwnerName} • To: {$toOwnerName}";
            
            if ($transaction->unit) {
                $description .= " • Unit: {$transaction->unit->unit_name}";
            }
            
            if ($transaction->particulars) {
                $description .= " • Particulars: {$transaction->particulars}";
            }
            
            // Build metadata
            $voucherDate = $transaction->voucher_date ? $transaction->voucher_date->format('Y-m-d') : null;
            $unitName = $transaction->unit ? $transaction->unit->unit_name : null;
            
            $metadata = [
                'transaction_id' => $transaction->id,
                'voucher_no' => $transaction->voucher_no,
                'voucher_date' => $voucherDate,
                'trans_method' => $transMethod,
                'trans_type' => $transaction->trans_type,
                'from_owner_id' => $transaction->from_owner_id,
                'from_owner_name' => $fromOwnerName,
                'to_owner_id' => $transaction->to_owner_id,
                'to_owner_name' => $toOwnerName,
                'unit_id' => $transaction->unit_id,
                'unit_name' => $unitName,
                'amount' => (float) $transaction->amount,
                'fund_reference' => $transaction->fund_reference,
                'particulars' => $transaction->particulars,
                'person_in_charge' => $transaction->person_in_charge,
                'instruments_count' => $transaction->instruments->count(),
                'attachments_count' => $transaction->attachments->count(),
            ];
            
            // Create activity log entry
            $log = ActivityLog::create([
                'activity_type' => 'TRANSACTION',
                'action' => 'CREATE',
                'status' => $status,
                'title' => $title,
                'description' => $description,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'user_email' => $user->email,
                'target_id' => $transaction->id,
                'target_type' => Transaction::class,
                'metadata' => $metadata,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'created_at' => now(),
            ]);
            
            Log::info('Activity log created successfully', [
                'log_id' => $log->id,
                'activity_type' => 'TRANSACTION',
                'transaction_id' => $transaction->id,
            ]);
        } catch (\Exception $e) {
            // Don't fail transaction creation if activity logging fails
            Log::error('Failed to log transaction activity', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
