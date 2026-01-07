<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PeaceLink Re-Engineering Migration
 * Adds new fields required for SPH (Secure Payment Hold) functionality
 * Based on Re-Engineering Specification v2.0
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('escrows', function (Blueprint $table) {
            // Reference number for human-readable identification
            $table->string('reference_number', 20)->nullable()->unique()->after('escrow_id');
            
            // DSP wallet number for payout
            $table->string('dsp_wallet_number', 50)->nullable()->after('delivery_id');
            
            // Assigned driver (if DSP company assigns specific driver)
            $table->unsignedBigInteger('assigned_driver_id')->nullable()->after('dsp_wallet_number');
            
            // Policy snapshot (frozen at creation)
            $table->json('policy_snapshot')->nullable()->after('details');
            
            // Fee snapshot (frozen at creation)
            $table->json('fee_snapshot')->nullable()->after('policy_snapshot');
            
            // Item amount (separate from delivery fee)
            $table->decimal('item_amount', 15, 2)->default(0)->after('amount');
            
            // Delivery fee
            $table->decimal('delivery_fee', 15, 2)->default(0)->after('item_amount');
            
            // Who pays delivery fee
            $table->string('delivery_fee_paid_by', 20)->default('buyer')->after('delivery_fee');
            
            // Advance payment percentage
            $table->decimal('advance_percentage', 5, 2)->default(0)->after('delivery_fee_paid_by');
            
            // Advance amount
            $table->decimal('advance_amount', 15, 2)->default(0)->after('advance_percentage');
            
            // Advance paid flag
            $table->boolean('advance_paid')->default(false)->after('advance_amount');
            
            // OTP fields
            $table->string('otp_hash', 255)->nullable()->after('pin_code');
            $table->timestamp('otp_generated_at')->nullable()->after('otp_hash');
            $table->timestamp('otp_expires_at')->nullable()->after('otp_generated_at');
            $table->integer('otp_attempts')->default(0)->after('otp_expires_at');
            $table->timestamp('otp_verified_at')->nullable()->after('otp_attempts');
            $table->unsignedBigInteger('otp_verified_by')->nullable()->after('otp_verified_at');
            
            // Timestamps for state transitions
            $table->timestamp('expires_at')->nullable()->after('updated_at');
            $table->timestamp('max_delivery_at')->nullable()->after('expires_at');
            $table->timestamp('approved_at')->nullable()->after('max_delivery_at');
            $table->timestamp('dsp_assigned_at')->nullable()->after('approved_at');
            $table->timestamp('delivered_at')->nullable()->after('dsp_assigned_at');
            $table->timestamp('canceled_at')->nullable()->after('delivered_at');
            
            // Cancellation tracking
            $table->string('canceled_by', 20)->nullable()->after('canceled_at');
            $table->text('cancellation_reason')->nullable()->after('canceled_by');
            
            // DSP reassignment tracking
            $table->integer('dsp_reassignment_count')->default(0)->after('cancellation_reason');
            
            // Optimistic locking
            $table->integer('version')->default(1)->after('dsp_reassignment_count');
            
            // Indexes
            $table->index('reference_number');
            $table->index('status');
            $table->index('delivery_id');
            $table->index(['user_id', 'status']);
            $table->index(['buyer_or_seller_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('escrows', function (Blueprint $table) {
            $table->dropColumn([
                'reference_number',
                'dsp_wallet_number',
                'assigned_driver_id',
                'policy_snapshot',
                'fee_snapshot',
                'item_amount',
                'delivery_fee',
                'delivery_fee_paid_by',
                'advance_percentage',
                'advance_amount',
                'advance_paid',
                'otp_hash',
                'otp_generated_at',
                'otp_expires_at',
                'otp_attempts',
                'otp_verified_at',
                'otp_verified_by',
                'expires_at',
                'max_delivery_at',
                'approved_at',
                'dsp_assigned_at',
                'delivered_at',
                'canceled_at',
                'canceled_by',
                'cancellation_reason',
                'dsp_reassignment_count',
                'version',
            ]);
        });
    }
};
