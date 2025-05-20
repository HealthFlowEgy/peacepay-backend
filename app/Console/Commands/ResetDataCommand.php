<?php

namespace App\Console\Commands;

use App\Models\Escrow;
use App\Models\EscrowDetails;
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
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        $UserWallet = UserWallet::get();
        foreach ($UserWallet as $wallet) {
            $wallet->balance = 0;
            $wallet->save();
        }

        EscrowDetails::truncate();
        Escrow::truncate();

        DB::statement('SET FOREIGN_KEY_CHECKS=1;'); // Don't forget to enable them again



        return Command::SUCCESS;
    }
}
