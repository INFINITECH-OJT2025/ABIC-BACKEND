<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Recalculate owner_ledger_entries with proper Main (Asset) / Client (Liability) accounting.
     * - from_owner: CREDIT | to_owner: DEBIT
     * - Main: debit=increase, credit=decrease | Client: credit=increase, debit=decrease
     */
    public function up(): void
    {
        DB::table('owner_ledger_entries')->truncate();

        $transactions = DB::table('transactions')
            ->where('status', 'ACTIVE')
            ->whereNotNull('from_owner_id')
            ->whereNotNull('to_owner_id')
            ->orderByRaw('COALESCE(voucher_date, created_at)')
            ->orderBy('id')
            ->get();

        $ownerBalances = [];
        $owners = DB::table('owners')->get()->keyBy('id');

        foreach ($transactions as $t) {
            $amount = (float) $t->amount;
            $fromId = (int) $t->from_owner_id;
            $toId = (int) $t->to_owner_id;

            $instrumentNo = DB::table('transaction_instruments')
                ->where('transaction_id', $t->id)
                ->pluck('instrument_no')
                ->filter()
                ->implode(', ') ?: null;

            $particulars = $t->particulars ?? '';
            $unit = DB::table('units')->find($t->unit_id);
            if ($unit) {
                $particulars = ($unit->unit_name ?? '') . ' - ' . $particulars;
            }

            $voucherDate = $t->voucher_date ? date('Y-m-d', strtotime($t->voucher_date)) : null;

            // From owner: CREDIT
            $fromType = $owners->get($fromId)?->owner_type ?? null;
            $prevFrom = $ownerBalances[$fromId] ?? 0;
            $newFrom = $fromType === 'MAIN' ? $prevFrom - $amount : $prevFrom + $amount;
            $ownerBalances[$fromId] = $newFrom;

            DB::table('owner_ledger_entries')->insert([
                'owner_id' => $fromId,
                'transaction_id' => $t->id,
                'voucher_no' => $t->voucher_no ?? '—',
                'voucher_date' => $voucherDate,
                'instrument_no' => $instrumentNo,
                'debit' => 0,
                'credit' => $amount,
                'running_balance' => $newFrom,
                'unit_id' => $t->unit_id,
                'particulars' => $particulars,
                'transfer_group_id' => $t->transfer_group_id,
                'created_at' => $t->created_at ?? now(),
            ]);

            // To owner: DEBIT
            $toType = $owners->get($toId)?->owner_type ?? null;
            $prevTo = $ownerBalances[$toId] ?? 0;
            $newTo = $toType === 'MAIN' ? $prevTo + $amount : $prevTo - $amount;
            $ownerBalances[$toId] = $newTo;

            DB::table('owner_ledger_entries')->insert([
                'owner_id' => $toId,
                'transaction_id' => $t->id,
                'voucher_no' => $t->voucher_no ?? '—',
                'voucher_date' => $voucherDate,
                'instrument_no' => $instrumentNo,
                'debit' => $amount,
                'credit' => 0,
                'running_balance' => $newTo,
                'unit_id' => $t->unit_id,
                'particulars' => $particulars,
                'transfer_group_id' => $t->transfer_group_id,
                'created_at' => $t->created_at ?? now(),
            ]);
        }
    }

    public function down(): void
    {
        // Cannot safely reverse - would need to re-run old backfill
    }
};
