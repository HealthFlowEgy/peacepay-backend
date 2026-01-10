<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletService
{
    /**
     * Get user's wallet balance
     */
    public function getBalance(User $user): float
    {
        return $user->wallet?->balance ?? 0.00;
    }

    /**
     * Credit amount to user's wallet
     */
    public function credit(User $user, float $amount, string $description = '', ?string $reference = null): bool
    {
        return DB::transaction(function () use ($user, $amount, $description, $reference) {
            $wallet = $user->wallet;
            
            if (!$wallet) {
                $wallet = Wallet::create([
                    'user_id' => $user->id,
                    'balance' => 0,
                ]);
            }

            $wallet->increment('balance', $amount);

            Log::info('Wallet credited', [
                'user_id' => $user->id,
                'amount' => $amount,
                'new_balance' => $wallet->fresh()->balance,
                'reference' => $reference,
            ]);

            return true;
        });
    }

    /**
     * Debit amount from user's wallet
     */
    public function debit(User $user, float $amount, string $description = '', ?string $reference = null): bool
    {
        return DB::transaction(function () use ($user, $amount, $description, $reference) {
            $wallet = $user->wallet;

            if (!$wallet || $wallet->balance < $amount) {
                Log::warning('Insufficient wallet balance', [
                    'user_id' => $user->id,
                    'requested' => $amount,
                    'available' => $wallet?->balance ?? 0,
                ]);
                return false;
            }

            $wallet->decrement('balance', $amount);

            Log::info('Wallet debited', [
                'user_id' => $user->id,
                'amount' => $amount,
                'new_balance' => $wallet->fresh()->balance,
                'reference' => $reference,
            ]);

            return true;
        });
    }

    /**
     * Transfer between wallets
     */
    public function transfer(User $from, User $to, float $amount, string $description = ''): bool
    {
        return DB::transaction(function () use ($from, $to, $amount, $description) {
            if (!$this->debit($from, $amount, "Transfer out: {$description}")) {
                return false;
            }

            $this->credit($to, $amount, "Transfer in: {$description}");

            return true;
        });
    }

    /**
     * Hold amount in escrow
     */
    public function holdForEscrow(User $user, float $amount, string $escrowId): bool
    {
        return $this->debit($user, $amount, "Escrow hold", $escrowId);
    }

    /**
     * Release escrow to recipient
     */
    public function releaseEscrow(User $recipient, float $amount, string $escrowId): bool
    {
        return $this->credit($recipient, $amount, "Escrow release", $escrowId);
    }
}
