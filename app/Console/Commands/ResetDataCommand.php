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

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        // Reset user wallet balances
        $this->info('Resetting user wallet balances...');
        $UserWallet = UserWallet::get();
        foreach ($UserWallet as $wallet) {
            $wallet->balance = 0;
            $wallet->save();
        }
        $this->info('User wallets reset: ' . $UserWallet->count());

        // Truncate tables
        $this->info('Truncating escrow details...');
        EscrowDetails::truncate();

        $this->info('Truncating escrows...');
        Escrow::truncate();

        $this->info('Truncating transaction details...');
        TransactionDetails::truncate();

        $this->info('Truncating transactions...');
        Transaction::truncate();

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->info('Data reset completed successfully!');

        return Command::SUCCESS;
    }
}
