<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserKycData;
use App\Models\UserWallet;
use App\Models\UserPasswordReset;
use App\Models\UserLoginLog;
use App\Models\UserNotification;
use App\Models\UserMailLog;
use App\Models\UserDevice;
use App\Models\UserAuthorization;
use App\Models\UserSupportTicket;
use App\Models\UserSupportTicketAttachment;
use App\Models\UserSupportChat;
use App\Models\Transaction;
use App\Models\TransactionDetails;
use App\Models\Escrow;
use App\Models\EscrowDetails;
use App\Models\EscrowChat;
use App\Models\EscrowConversationAttachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeleteAllUsersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'users:delete-all {--force : Force deletion without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete all users and their related data from the system';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if (!$this->option('force')) {
            if (!$this->confirm('This will permanently delete ALL users and their related data. Are you sure?')) {
                $this->info('Operation cancelled.');
                return Command::SUCCESS;
            }
        }

        $this->info('Starting deletion of all users and related data...');

        try {
            DB::beginTransaction();

            // Disable foreign key checks to avoid constraint issues
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            $userCount = User::count();
            $this->info("Found {$userCount} users to delete.");

            if ($userCount === 0) {
                $this->info('No users found to delete.');
                DB::rollBack();
                return Command::SUCCESS;
            }

            // Delete related data first
            $this->deleteRelatedData();

            // Finally delete all users
            User::truncate();

            // Re-enable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            DB::commit();

            $this->info("Successfully deleted {$userCount} users and all related data.");

        } catch (\Exception $e) {
            DB::rollBack();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $this->error('Failed to delete users: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Delete all user-related data
     */
    private function deleteRelatedData()
    {
        $this->info('Deleting user-related data...');

        // Delete escrow-related data (users can be involved in escrows)
        $this->line('- Deleting escrow conversation attachments...');
        EscrowConversationAttachment::truncate();

        $this->line('- Deleting escrow chats...');
        EscrowChat::truncate();

        $this->line('- Deleting escrow details...');
        EscrowDetails::truncate();

        $this->line('- Deleting escrows...');
        Escrow::truncate();

        // Delete transaction-related data
        $this->line('- Deleting transaction details...');
        TransactionDetails::truncate();

        $this->line('- Deleting transactions...');
        Transaction::truncate();

        // Delete user support data
        $this->line('- Deleting user support ticket attachments...');
        UserSupportTicketAttachment::truncate();

        $this->line('- Deleting user support chats...');
        UserSupportChat::truncate();

        $this->line('- Deleting user support tickets...');
        UserSupportTicket::truncate();

        // Delete user authentication and security data
        $this->line('- Deleting user authorizations...');
        UserAuthorization::truncate();

        $this->line('- Deleting user devices...');
        UserDevice::truncate();

        // Delete user communication data
        $this->line('- Deleting user notifications...');
        UserNotification::truncate();

        $this->line('- Deleting user mail logs...');
        UserMailLog::truncate();

        // Delete user financial data
        $this->line('- Deleting user wallets...');
        UserWallet::truncate();

        // Delete user account data
        $this->line('- Deleting user login logs...');
        UserLoginLog::truncate();

        $this->line('- Deleting user password resets...');
        UserPasswordReset::truncate();

        $this->line('- Deleting user KYC data...');
        UserKycData::truncate();

        $this->info('All related data deleted successfully.');
    }
}