<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enhance Cashout Requests Table
 * Adds fee tracking fields for proper fee handling
 * BUG FIX: Cash-out fee must be deducted at REQUEST time, not approval
 * Based on Re-Engineering Specification v2.0
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if money_out_requests table exists (from QRPay template)
        if (Schema::hasTable('money_out_requests')) {
            Schema::table('money_out_requests', function (Blueprint $table) {
                // Fee deducted at request time
                if (!Schema::hasColumn('money_out_requests', 'fee_deducted_at_request')) {
                    $table->boolean('fee_deducted_at_request')->default(false)->after('total_charge');
                }
                
                // Fee amount (for refund on rejection)
                if (!Schema::hasColumn('money_out_requests', 'fee_amount')) {
                    $table->decimal('fee_amount', 15, 2)->default(0)->after('fee_deducted_at_request');
                }
                
                // Net amount user receives
                if (!Schema::hasColumn('money_out_requests', 'net_amount')) {
                    $table->decimal('net_amount', 15, 2)->default(0)->after('fee_amount');
                }
                
                // Fee refunded flag (if rejected)
                if (!Schema::hasColumn('money_out_requests', 'fee_refunded')) {
                    $table->boolean('fee_refunded')->default(false)->after('net_amount');
                }
                
                // Fee refunded at timestamp
                if (!Schema::hasColumn('money_out_requests', 'fee_refunded_at')) {
                    $table->timestamp('fee_refunded_at')->nullable()->after('fee_refunded');
                }
                
                // Rejection reason
                if (!Schema::hasColumn('money_out_requests', 'rejection_reason')) {
                    $table->text('rejection_reason')->nullable()->after('fee_refunded_at');
                }
            });
        }

        // Create cashout_requests table if it doesn't exist
        if (!Schema::hasTable('cashout_requests')) {
            Schema::create('cashout_requests', function (Blueprint $table) {
                $table->id();
                $table->uuid('request_id')->unique();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('wallet_id');
                $table->unsignedBigInteger('cashout_method_id')->nullable();
                $table->decimal('requested_amount', 15, 2);
                $table->decimal('fee_amount', 15, 2); // Calculated and deducted at request
                $table->decimal('net_amount', 15, 2); // Amount user receives
                $table->string('status', 20)->default('pending'); // pending, approved, rejected, processing, completed, failed
                
                // Fee tracking
                $table->boolean('fee_deducted_at_request')->default(true);
                $table->boolean('fee_refunded')->default(false);
                $table->timestamp('fee_refunded_at')->nullable();
                
                // Processing
                $table->unsignedBigInteger('processed_by')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->text('rejection_reason')->nullable();
                
                // External reference
                $table->string('external_reference', 100)->nullable();
                $table->string('external_status', 50)->nullable();
                
                $table->timestamps();
                
                $table->index('user_id');
                $table->index('status');
                $table->index('created_at');
                
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('wallet_id')->references('id')->on('user_wallets')->onDelete('cascade');
                $table->foreign('processed_by')->references('id')->on('users')->onDelete('set null');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('money_out_requests')) {
            Schema::table('money_out_requests', function (Blueprint $table) {
                $table->dropColumn([
                    'fee_deducted_at_request',
                    'fee_amount',
                    'net_amount',
                    'fee_refunded',
                    'fee_refunded_at',
                    'rejection_reason',
                ]);
            });
        }

        Schema::dropIfExists('cashout_requests');
    }
};
