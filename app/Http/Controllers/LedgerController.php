<?php

namespace App\Http\Controllers;

use App\Models\OwnerLedgerEntry;
use App\Models\Owner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LedgerController extends Controller
{
    /**
     * Get mains ledger for a MAIN type owner.
     * Uses precomputed owner_ledger_entries for fast retrieval.
     */
    public function mains(Request $request)
    {
        $ownerId = $request->input('owner_id');

        if (!$ownerId) {
            return response()->json([
                'success' => false,
                'message' => 'owner_id is required',
                'data' => null
            ], 422);
        }

        $owner = Owner::find($ownerId);
        if (!$owner) {
            return response()->json([
                'success' => false,
                'message' => 'Owner not found',
                'data' => null
            ], 404);
        }

        if ($owner->owner_type !== 'MAIN') {
            return response()->json([
                'success' => false,
                'message' => 'Owner must be of type MAIN',
                'data' => null
            ], 422);
        }

        return $this->getLedgerForOwner($ownerId, $request);
    }

    /**
     * Get clients ledger for a CLIENT type owner.
     * Uses precomputed owner_ledger_entries for fast retrieval.
     */
    public function clients(Request $request)
    {
        $ownerId = $request->input('owner_id');

        if (!$ownerId) {
            return response()->json([
                'success' => false,
                'message' => 'owner_id is required',
                'data' => null
            ], 422);
        }

        $owner = Owner::find($ownerId);
        if (!$owner) {
            return response()->json([
                'success' => false,
                'message' => 'Owner not found',
                'data' => null
            ], 404);
        }

        if ($owner->owner_type !== 'CLIENT') {
            return response()->json([
                'success' => false,
                'message' => 'Owner must be of type CLIENT',
                'data' => null
            ], 422);
        }

        return $this->getLedgerForOwner($ownerId, $request);
    }

    /**
     * Get company ledger for a COMPANY type owner.
     * Uses precomputed owner_ledger_entries for fast retrieval.
     */
    public function company(Request $request)
    {
        $ownerId = $request->input('owner_id');

        if (!$ownerId) {
            return response()->json([
                'success' => false,
                'message' => 'owner_id is required',
                'data' => null
            ], 422);
        }

        $owner = Owner::find($ownerId);
        if (!$owner) {
            return response()->json([
                'success' => false,
                'message' => 'Owner not found',
                'data' => null
            ], 404);
        }

        if ($owner->owner_type !== 'COMPANY') {
            return response()->json([
                'success' => false,
                'message' => 'Owner must be of type COMPANY',
                'data' => null
            ], 422);
        }

        return $this->getLedgerForOwner($ownerId, $request);
    }

    /**
     * Get system ledger for the SYSTEM type owner.
     * Uses precomputed owner_ledger_entries - auto-loads SYSTEM owner.
     */
    public function system(Request $request)
    {
        $systemOwner = Owner::where('owner_type', 'SYSTEM')
            ->where(function ($q) {
                $q->where('owner_code', 'SYS-000')
                  ->orWhere('is_system', true);
            })
            ->first();

        if (!$systemOwner) {
            return response()->json([
                'success' => false,
                'message' => 'SYSTEM owner not found. Please seed the SYSTEM owner first.',
                'data' => null
            ], 404);
        }

        return $this->getLedgerForOwner($systemOwner->id, $request);
    }

    /**
     * Get ledger entries for an owner from owner_ledger_entries.
     * Joins with transaction only for attachments and other-party info.
     */
    protected function getLedgerForOwner(int $ownerId, Request $request)
    {
        $owner = Owner::find($ownerId);
        $sortOrder = $request->input('sort', 'newest') === 'oldest' ? 'asc' : 'desc';

        $entries = OwnerLedgerEntry::query()
            ->where('owner_id', $ownerId)
            ->with(['transaction' => function ($q) {
                $q->with(['fromOwner', 'toOwner', 'unit', 'instruments', 'attachments']);
                // Filter only posted transactions if is_posted field exists
                // Note: This will be enabled once is_posted migration is added
                // ->where('is_posted', true);
            }])
            ->orderBy('created_at', $sortOrder) // Order by full timestamp (date + time + microseconds) for precise ordering
            ->orderBy('id', $sortOrder) // Secondary sort for consistent ordering when timestamps are identical
            ->get();

        $ownerType = $owner->owner_type ?? null;
        $rows = $entries->map(function ($entry) use ($ownerId, $ownerType) {
            $t = $entry->transaction;
            $isFromOwner = $t && (int) $t->from_owner_id === (int) $ownerId;
            $otherOwner = $isFromOwner ? $t->toOwner : $t->fromOwner;
            $ownerName = $otherOwner?->name ?? '—';

            // Smart date handling: Use voucher_date if available, otherwise use transaction created_at
            $hasVoucherDate = $entry->voucher_date !== null;
            $displayDate = $hasVoucherDate 
                ? $entry->voucher_date->format('F j, Y')
                : ($t && $t->created_at 
                    ? $t->created_at->format('F j, Y')
                    : ($entry->created_at 
                        ? $entry->created_at->format('F j, Y')
                        : '##########'));

            // Main/System (Asset): deposit = debit, withdrawal = credit
            // Client/Company (Liability): deposit = credit, withdrawal = debit
            $isAssetAccount = $ownerType === 'MAIN' || $ownerType === 'SYSTEM';
            $deposit = $isAssetAccount ? (float) $entry->debit : (float) $entry->credit;
            $withdrawal = $isAssetAccount ? (float) $entry->credit : (float) $entry->debit;

            $voucherAttachmentUrl = null;
            $instrumentAttachments = [];
            $transType = '—';

            if ($t) {
                // Get instrument numbers from transaction_instruments
                $instrumentNos = $t->instruments
                    ->pluck('instrument_no')
                    ->filter()
                    ->implode(', ') ?: null;
                
                // Set transType to instrument numbers or transaction type as fallback
                $transType = $instrumentNos ?? ($t->trans_type ?? '—');

                $voucherAttachment = null;
                if ($t->voucher_no) {
                    $voucherAttachment = $t->attachments->first(function ($att) use ($t) {
                        if (!$att->file_path && !$att->temp_path) return false;
                        return str_contains($att->file_name, $t->voucher_no) ||
                            ($att->file_path && str_contains($att->file_path, $t->voucher_no));
                    });
                }
                if (!$voucherAttachment && $t->attachments->isNotEmpty()) {
                    $voucherAttachment = $t->attachments->first();
                }
                // Check if attachment exists (Firebase URL, local storage, or temp for pending uploads)
                $attachmentExists = false;
                if ($voucherAttachment) {
                    $fp = $voucherAttachment->file_path;
                    $tp = $voucherAttachment->temp_path ?? null;
                    if ($fp && (str_starts_with($fp, 'http://') || str_starts_with($fp, 'https://'))) {
                        $attachmentExists = true;
                        $voucherAttachmentUrl = $fp;
                    } elseif ($fp && Storage::disk('local')->exists($fp)) {
                        $attachmentExists = true;
                        $voucherAttachmentUrl = "/api/accountant/transactions/{$t->id}/attachments/{$voucherAttachment->id}";
                    } elseif ($tp && Storage::disk('local')->exists($tp)) {
                        // Pending upload - serve from temp
                        $attachmentExists = true;
                        $voucherAttachmentUrl = "/api/accountant/transactions/{$t->id}/attachments/{$voucherAttachment->id}";
                    }
                }

                // Filter attachments that exist (Firebase URLs, local files, or temp for pending)
                $attachmentsList = $t->attachments->filter(function ($a) {
                    if ($a->file_path && (str_starts_with($a->file_path, 'http://') || str_starts_with($a->file_path, 'https://'))) {
                        return true;
                    }
                    if ($a->file_path && Storage::disk('local')->exists($a->file_path)) {
                        return true;
                    }
                    if ($a->temp_path && Storage::disk('local')->exists($a->temp_path)) {
                        return true; // Pending upload - temp file exists
                    }
                    return false;
                })->values();

                // Exclude voucher from instrument attachments - voucher and instrument images are different
                // Upload order: voucher first, then file_0, file_1... for instruments
                $attachmentsForInstruments = $voucherAttachment
                    ? $attachmentsList->reject(fn ($a) => $a->id === $voucherAttachment->id)->values()
                    : $attachmentsList;

                foreach ($t->instruments as $idx => $inst) {
                    $att = $attachmentsForInstruments->get($idx);
                    if ($att) {
                        $fp = $att->file_path;
                        $tp = $att->temp_path ?? null;
                        $hasUrl = $fp && (str_starts_with($fp, 'http://') || str_starts_with($fp, 'https://'));
                        $hasLocal = ($fp && Storage::disk('local')->exists($fp)) || ($tp && Storage::disk('local')->exists($tp));
                        $attachmentUrl = $hasUrl ? $fp : "/api/accountant/transactions/{$t->id}/attachments/{$att->id}";
                        
                        $instrumentAttachments[] = [
                            'instrumentNo' => $inst->instrument_no ?? '—',
                            'instrumentType' => $inst->instrument_type ?? '—',
                            'attachmentUrl' => $attachmentUrl,
                        ];
                    }
                }
            }

            return [
                'id' => $entry->id,
                'transactionId' => $entry->transaction_id,
                'createdAt' => $entry->created_at?->toIso8601String() ?? '',
                'voucherDate' => $displayDate,
                'isVoucherDate' => $hasVoucherDate,
                'voucherNo' => $entry->voucher_no ?? '—',
                'otherOwnerId' => $otherOwner?->id ?? null,
                'otherOwnerType' => $otherOwner?->owner_type ?? null,
                'transType' => $transType,
                'owner' => $ownerName,
                'particulars' => $entry->particulars ?? '',
                'deposit' => $deposit,
                'withdrawal' => $withdrawal,
                'outsBalance' => (float) $entry->running_balance,
                'transferGroupId' => $entry->transfer_group_id,
                'voucherAttachmentUrl' => $voucherAttachmentUrl,
                'instrumentAttachments' => $instrumentAttachments,
                'fundReference' => $t?->fund_reference ?? null,
                'personInCharge' => $t?->person_in_charge ?? null,
            ];
        });

        // Calculate opening balance: balance BEFORE the earliest transaction
        // IMPORTANT: Always find earliest by querying separately, NOT from sorted $entries
        // This ensures opening balance is consistent regardless of sort order
        $openingBalance = 0;
        
        if ($entries->isNotEmpty()) {
            // Query for the earliest entry independently (always ASC order, regardless of request sort)
            // This ensures opening balance is consistent regardless of sort order
            $earliestEntry = OwnerLedgerEntry::query()
                ->where('owner_id', $ownerId)
                ->with('transaction.fromOwner') // Need transaction for opening balance check
                ->orderBy('created_at', 'asc')
                ->orderBy('id', 'asc')
                ->first();
            
            if ($earliestEntry && $earliestEntry->transaction) {
                $t = $earliestEntry->transaction;
                
                // Check if this is an opening transaction
                $isOpeningTransaction = 
                    str_starts_with($t->voucher_no ?? '', 'OPN-') ||
                    stripos($t->particulars ?? '', 'Opening Balance') !== false ||
                    ($t->fromOwner && $t->fromOwner->owner_type === 'SYSTEM' && stripos($t->particulars ?? '', 'Opening') !== false);
                
                if ($isOpeningTransaction) {
                    // If it's an opening transaction, the opening balance is the transaction amount
                    // For MAIN/SYSTEM: opening transaction creates a debit (deposit)
                    // For CLIENT/COMPANY: opening transaction creates a credit (deposit)
                    $isAssetAccount = $ownerType === 'MAIN' || $ownerType === 'SYSTEM';
                    if ($isAssetAccount) {
                        $openingBalance = (float) $earliestEntry->debit; // Opening = debit amount for MAIN/SYSTEM
                    } else {
                        $openingBalance = (float) $earliestEntry->credit; // Opening = credit amount for CLIENT
                    }
                } else {
                    // Not an opening transaction - calculate balance before this entry
                    // For MAIN/SYSTEM (Asset): debit increases balance, credit decreases
                    // For CLIENT/COMPANY (Liability): credit increases balance, debit decreases
                    $isAssetAccount = $ownerType === 'MAIN' || $ownerType === 'SYSTEM';
                    if ($isAssetAccount) {
                        // Main/System: opening = running_balance - debit + credit
                        $openingBalance = (float) $earliestEntry->running_balance - (float) $earliestEntry->debit + (float) $earliestEntry->credit;
                    } else {
                        // Client: opening = running_balance - credit + debit
                        $openingBalance = (float) $earliestEntry->running_balance - (float) $earliestEntry->credit + (float) $earliestEntry->debit;
                    }
                }
            }
        }

        $ownerTypeForMessage = $owner->owner_type ?? null;
        $messages = [
            'MAIN' => 'Mains ledger retrieved successfully',
            'CLIENT' => 'Clients ledger retrieved successfully',
            'COMPANY' => 'Company ledger retrieved successfully',
            'SYSTEM' => 'System ledger retrieved successfully',
        ];
        $message = $messages[$ownerTypeForMessage] ?? 'Ledger retrieved successfully';
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'transactions' => $rows,
                'owner' => $owner,
                'openingBalance' => $openingBalance,
            ]
        ]);
    }
}
