<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 1. Add composite index (owner_id, unit_id, created_at) for efficient per-unit ledger lookups.
     * 2. Recalculate owner_ledger_entries with correct per-unit running balances.
     *
     * When an owner has many units, each unit's ledger must have its own running balance.
     * Previously, running balances were mixed across units, causing incorrect totals.
     */
    public function up(): void
    {
        // Add composite index for owner_id + unit_id queries (used when getting prev balance)
        Schema::table('owner_ledger_entries', function (Blueprint $table) {
            $table->index(['owner_id', 'unit_id', 'created_at']);
        });

        // Recalculate all entries with proper per-(owner_id, unit_id) running balances
        DB::table('owner_ledger_entries')->truncate();

        $transactions = DB::table('transactions')
            ->where('status', 'ACTIVE')
            ->whereNotNull('from_owner_id')
            ->whereNotNull('to_owner_id')
            ->orderByRaw('COALESCE(voucher_date, created_at)')
            ->orderBy('id')
            ->get();

        $owners = DB::table('owners')->get()->keyBy('id');

        // Track balance per (owner_id, unit_id) - use 'null' string for unit_id=null
        $balances = [];

        foreach ($transactions as $t) {
            $amount = (float) $t->amount;
            $fromId = (int) $t->from_owner_id;
            $toId = (int) $t->to_owner_id;
            $unitId = $t->unit_id;
            $transMethod = $t->trans_method ?? '';
            $isDeposit = $transMethod === 'DEPOSIT';
            $isWithdrawal = $transMethod === 'WITHDRAWAL';
            $isOpening = $transMethod === 'TRANSFER' && $owners->get($fromId)?->owner_type === 'SYSTEM';

            $instrumentNo = DB::table('transaction_instruments')
                ->where('transaction_id', $t->id)
                ->pluck('instrument_no')
                ->filter()
                ->implode(', ') ?: null;

            $particulars = $t->particulars ?? '';
            $unit = $unitId ? DB::table('units')->find($unitId) : null;
            if ($unit) {
                $particulars = ($unit->unit_name ?? '') . ' - ' . $particulars;
            }

            $voucherDate = $t->voucher_date ? date('Y-m-d', strtotime($t->voucher_date)) : null;

            // From owner: always unit_id=null (MAIN/SYSTEM). Trust Account Model.
            $fromType = $owners->get($fromId)?->owner_type ?? null;
            $fromKey = $fromId . ':' . 'null';
            $prevFrom = $balances[$fromKey] ?? 0;
            $isAssetFrom = $fromType === 'MAIN' || $fromType === 'SYSTEM';
            if ($isOpening) {
                // Opening: SYSTEM shows deposit (debit=increase); other from types follow same pattern
                $fromDebit = ($fromType === 'SYSTEM' || $fromType === 'MAIN') ? $amount : 0;
                $fromCredit = ($fromType === 'SYSTEM' || $fromType === 'MAIN') ? 0 : $amount;
                $newFrom = $prevFrom + $amount;
            } elseif ($isAssetFrom) {
                $fromDebit = $isDeposit ? $amount : 0;
                $fromCredit = $isDeposit ? 0 : $amount;
                $newFrom = $isDeposit ? $prevFrom + $amount : $prevFrom - $amount;
            } else {
                $fromDebit = $isDeposit ? 0 : $amount;
                $fromCredit = $isDeposit ? $amount : 0;
                $newFrom = $isDeposit ? $prevFrom + $amount : $prevFrom - $amount;
            }
            $balances[$fromKey] = $newFrom;

            DB::table('owner_ledger_entries')->insert([
                'owner_id' => $fromId,
                'transaction_id' => $t->id,
                'voucher_no' => $t->voucher_no ?? '—',
                'voucher_date' => $voucherDate,
                'instrument_no' => $instrumentNo,
                'debit' => $fromDebit,
                'credit' => $fromCredit,
                'running_balance' => $newFrom,
                'unit_id' => null,
                'particulars' => $particulars,
                'transfer_group_id' => $t->transfer_group_id,
                'created_at' => $t->created_at ?? now(),
            ]);

            // To owner: use transaction's unit_id (can be null for general ledger)
            $toType = $owners->get($toId)?->owner_type ?? null;
            $toKey = $toId . ':' . ($unitId ?? 'null');
            $prevTo = $balances[$toKey] ?? 0;
            $isAssetTo = $toType === 'MAIN' || $toType === 'SYSTEM';
            if ($isOpening) {
                $toDebit = $isAssetTo ? $amount : 0;
                $toCredit = $isAssetTo ? 0 : $amount;
                $newTo = $prevTo + $amount;
            } elseif ($isAssetTo) {
                $toDebit = $isDeposit ? $amount : 0;
                $toCredit = $isDeposit ? 0 : $amount;
                $newTo = $isDeposit ? $prevTo + $amount : $prevTo - $amount;
            } else {
                $toDebit = $isDeposit ? 0 : $amount;
                $toCredit = $isDeposit ? $amount : 0;
                $newTo = $isDeposit ? $prevTo + $amount : $prevTo - $amount;
            }
            $balances[$toKey] = $newTo;

            DB::table('owner_ledger_entries')->insert([
                'owner_id' => $toId,
                'transaction_id' => $t->id,
                'voucher_no' => $t->voucher_no ?? '—',
                'voucher_date' => $voucherDate,
                'instrument_no' => $instrumentNo,
                'debit' => $toDebit,
                'credit' => $toCredit,
                'running_balance' => $newTo,
                'unit_id' => $unitId,
                'particulars' => $particulars,
                'transfer_group_id' => $t->transfer_group_id,
                'created_at' => $t->created_at ?? now(),
            ]);
        }
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('owner_ledger_entries', function (Blueprint $table) {
            $table->dropIndex(['owner_id', 'unit_id', 'created_at']);
        });
        // Cannot safely restore previous ledger data - would need manual recalculation
    }
};
