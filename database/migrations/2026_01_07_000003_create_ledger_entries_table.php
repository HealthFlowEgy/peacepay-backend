<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger Entries Table
 * Append-only audit log for all financial transactions
 * Based on Re-Engineering Specification v2.0
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('entry_id')->unique();
            $table->unsignedBigInteger('escrow_id')->nullable(); // PeaceLink reference
            $table->unsignedBigInteger('debit_wallet_id')->nullable();
            $table->unsignedBigInteger('credit_wallet_id')->nullable();
            $table->string('platform_wallet_name', 100)->nullable(); // 'peacepay_profit'
            $table->decimal('amount', 15, 2);
            $table->string('entry_type', 50); // sph_hold, merchant_payout, dsp_payout, platform_fee, refund, advance_payout
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->string('idempotency_key', 255)->nullable()->unique();
            $table->timestamp('created_at')->useCurrent();
            
            $table->index('escrow_id');
            $table->index('entry_type');
            $table->index('created_at');
            $table->index(['debit_wallet_id', 'created_at']);
            $table->index(['credit_wallet_id', 'created_at']);
            
            $table->foreign('escrow_id')->references('id')->on('escrows')->onDelete('set null');
            $table->foreign('debit_wallet_id')->references('id')->on('user_wallets')->onDelete('set null');
            $table->foreign('credit_wallet_id')->references('id')->on('user_wallets')->onDelete('set null');
        });

        // Create platform wallet table for PeacePay profits
        Schema::create('platform_wallets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->decimal('balance', 15, 2)->default(0);
            $table->string('currency', 3)->default('EGP');
            $table->timestamps();
            $table->integer('version')->default(1); // Optimistic locking
        });

        // Insert default platform wallet
        DB::table('platform_wallets')->insert([
            'name' => 'peacepay_profit',
            'balance' => 0,
            'currency' => 'EGP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('platform_wallets');
    }
};
