<?php

namespace App\Console\Commands;

use App\Models\Escrow;
use App\Models\EscrowDetails;
use App\Models\Transaction;
use App\Models\TransactionDetails;
use App\Models\UserWallet;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset:data';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'reset data';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting data reset...');

        // Show counts before deletion
        $this->info('Current records:');
        $transactionCount = Transaction::count();
        $transactionDetailsCount = TransactionDetails::count();
        $escrowCount = Escrow::count();
        $escrowDetailsCount = EscrowDetails::count();

        $this->table(
            ['Table', 'Count'],
            [
                ['Transactions', $transactionCount],
                ['Transaction Details', $transactionDetailsCount],
                ['Escrows', $escrowCount],
                ['Escrow Details', $escrowDetailsCount],
            ]
        );

        if (!$this->confirm('Do you want to proceed with data reset?', true)) {
            $this->info('Data reset cancelled.');
            return Command::SUCCESS;
        }

        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            // Reset user wallet balances
            $this->info('Resetting user wallet balances...');
            $walletCount = UserWallet::count();
            UserWallet::query()->update(['balance' => 0]);
            $this->info('User wallets reset: ' . $walletCount);

            // Delete records instead of truncate for better compatibility
            $this->info('Deleting escrow details...');
            $deletedEscrowDetails = DB::table('escrow_details')->delete();
            $this->info('Deleted: ' . $deletedEscrowDetails);

            $this->info('Deleting escrows...');
            $deletedEscrows = DB::table('escrows')->delete();
            $this->info('Deleted: ' . $deletedEscrows);

            $this->info('Deleting transaction details...');
            $deletedTransactionDetails = DB::table('transaction_details')->delete();
            $this->info('Deleted: ' . $deletedTransactionDetails);

            $this->info('Deleting transactions...');
            $deletedTransactions = DB::table('transactions')->delete();
            $this->info('Deleted: ' . $deletedTransactions);

            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        } catch (\Exception $e) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            $this->error('Error occurred: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Show counts after deletion
        $this->info('Records after reset:');
        $this->table(
            ['Table', 'Count'],
            [
                ['Transactions', Transaction::count()],
                ['Transaction Details', TransactionDetails::count()],
                ['Escrows', Escrow::count()],
                ['Escrow Details', EscrowDetails::count()],
            ]
        );

        $this->info('Data reset completed successfully!');

        return Command::SUCCESS;
    }
}
