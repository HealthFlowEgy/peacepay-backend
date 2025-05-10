<?php

namespace App\Console\Commands;

use App\Models\Escrow;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CancelInactiveEscrowCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'escrow:cancel-inactive';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cancel Escrow payments with no action in the last 3 days';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting to check for inactive escrow payments...');
        
        // Find escrow payments with status 3 that have not been updated in the last 3 days
        $inactiveEscrows = Escrow::where('status', 3)
            ->where('updated_at', '<', Carbon::now()->subDays(3))
            ->get();
        
        $count = $inactiveEscrows->count();
        $this->info("Found {$count} inactive escrow payments to cancel.");
        
        foreach ($inactiveEscrows as $escrow) {
            try {
                // Use the same function that's used in the controller
                $this->info("Cancelling escrow #{$escrow->id}");
                
                returnEscrowMoney($escrow);
                
                $this->info("Escrow #{$escrow->id} cancelled successfully.");
            } catch (\Exception $e) {
                $this->error("Error cancelling escrow #{$escrow->id}: " . $e->getMessage());
            }
        }
        
        $this->info("Escrow cancellation process completed!");
        return Command::SUCCESS;
    }
}