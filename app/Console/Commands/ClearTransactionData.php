<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ClearTransactionData extends Command
{
    protected $signature = 'transactions:clear
                            {--confirm : Skip confirmation prompt}';

    protected $description = 'Clear all transactions, attachments, instruments, and owner_ledger_entries';

    public function handle(): int
    {
        if (!$this->option('confirm')) {
            if (!$this->confirm('This will permanently delete ALL transactions and related data. Continue?')) {
                $this->info('Aborted.');
                return 0;
            }
        }

        $this->info('Clearing transaction data...');

        DB::transaction(function () {
            $counts = [
                'owner_ledger_entries' => DB::table('owner_ledger_entries')->count(),
                'transaction_attachments' => DB::table('transaction_attachments')->count(),
                'transaction_instruments' => DB::table('transaction_instruments')->count(),
                'transactions' => DB::table('transactions')->count(),
            ];

            // Disable foreign key checks for truncate
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            DB::table('owner_ledger_entries')->truncate();
            $this->info("  Truncated owner_ledger_entries ({$counts['owner_ledger_entries']} rows)");

            DB::table('transaction_attachments')->truncate();
            $this->info("  Truncated transaction_attachments ({$counts['transaction_attachments']} rows)");

            DB::table('transaction_instruments')->truncate();
            $this->info("  Truncated transaction_instruments ({$counts['transaction_instruments']} rows)");

            DB::table('transactions')->truncate();
            $this->info("  Truncated transactions ({$counts['transactions']} rows)");

            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        });

        // Delete stored attachment files
        $attachmentPath = 'transaction-attachments';
        if (Storage::disk('local')->exists($attachmentPath)) {
            Storage::disk('local')->deleteDirectory($attachmentPath);
            $this->info("  Deleted attachment files from storage");
        }

        $this->newLine();
        $this->info('Done. All transaction data has been cleared.');

        return 0;
    }
}
