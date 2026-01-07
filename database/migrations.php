<?php
// database/migrations/2026_01_07_000001_create_users_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('phone', 20)->unique();
            $table->string('phone_country_code', 5)->default('+20');
            $table->string('email')->nullable()->unique();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('first_name_ar', 100)->nullable();
            $table->string('last_name_ar', 100)->nullable();
            $table->string('national_id', 20)->nullable();
            $table->string('pin_hash')->nullable();
            $table->enum('role', ['buyer', 'merchant', 'dsp', 'dsp_driver', 'admin'])->default('buyer');
            $table->enum('status', ['pending', 'active', 'suspended', 'blocked'])->default('pending');
            $table->enum('kyc_level', ['0', '1', '2', '3'])->default('0');
            $table->string('profile_photo_url')->nullable();
            $table->string('preferred_language', 5)->default('ar');
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('pin_set_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->smallInteger('failed_pin_attempts')->default(0);
            $table->timestamp('pin_locked_until')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['phone', 'status']);
            $table->index(['role', 'status']);
            $table->index('national_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};

// database/migrations/2026_01_07_000002_create_wallets_table.php

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('wallet_number', 20)->unique();
            $table->decimal('balance', 15, 2)->default(0);
            $table->decimal('held_balance', 15, 2)->default(0);
            $table->string('currency', 3)->default('EGP');
            $table->enum('status', ['active', 'suspended', 'closed'])->default('active');
            $table->unsignedInteger('version')->default(1); // Optimistic locking
            $table->timestamps();
            
            $table->index(['user_id', 'status']);
            $table->index('wallet_number');
            
            // Constraint: balance >= held_balance
            $table->check('balance >= held_balance');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};

// database/migrations/2026_01_07_000003_create_wallet_transactions_table.php

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('wallet_id')->constrained()->cascadeOnDelete();
            $table->enum('type', [
                'credit', 'debit', 'hold', 'release', 
                'topup', 'cashout', 'refund', 
                'peacelink_hold', 'peacelink_release', 
                'peacelink_payout', 'peacelink_refund',
                'fee', 'adjustment'
            ]);
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_before', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->decimal('held_before', 15, 2)->nullable();
            $table->decimal('held_after', 15, 2)->nullable();
            $table->string('reference_type', 50)->nullable();
            $table->uuid('reference_id')->nullable();
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['wallet_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};

// database/migrations/2026_01_07_000004_create_peacelinks_table.php

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('peacelinks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference_number', 20)->unique();
            
            // Parties
            $table->foreignUuid('merchant_id')->constrained('users');
            $table->foreignUuid('buyer_id')->nullable()->constrained('users');
            $table->string('buyer_phone', 20);
            $table->foreignUuid('dsp_id')->nullable()->constrained('users');
            $table->string('dsp_wallet_number', 20)->nullable();
            $table->foreignUuid('assigned_driver_id')->nullable()->constrained('users');
            
            // Policy & Fees (frozen at creation)
            $table->foreignUuid('policy_id')->nullable();
            $table->json('policy_snapshot')->nullable();
            $table->json('fee_snapshot');
            
            // Amounts
            $table->decimal('item_amount', 15, 2);
            $table->decimal('delivery_fee', 15, 2);
            $table->decimal('total_amount', 15, 2);
            $table->enum('delivery_fee_paid_by', ['buyer', 'merchant'])->default('buyer');
            $table->decimal('advance_percentage', 5, 2)->default(0);
            $table->decimal('advance_amount', 15, 2)->default(0);
            
            // Item details
            $table->string('item_description');
            $table->string('item_description_ar')->nullable();
            $table->unsignedSmallInteger('item_quantity')->default(1);
            $table->json('item_metadata')->nullable();
            
            // Status
            $table->enum('status', [
                'created', 'pending_approval', 'sph_active', 
                'dsp_assigned', 'otp_generated', 'delivered',
                'canceled', 'disputed', 'resolved', 'expired'
            ])->default('created');
            
            // OTP
            $table->string('otp_hash', 64)->nullable();
            $table->timestamp('otp_generated_at')->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->unsignedTinyInteger('otp_attempts')->default(0);
            $table->timestamp('otp_verified_at')->nullable();
            $table->foreignUuid('otp_verified_by')->nullable()->constrained('users');
            
            // Timestamps
            $table->timestamp('expires_at');
            $table->timestamp('max_delivery_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('dsp_assigned_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->enum('canceled_by', ['buyer', 'merchant', 'dsp', 'admin', 'system'])->nullable();
            $table->string('cancellation_reason')->nullable();
            
            // DSP reassignment
            $table->unsignedTinyInteger('dsp_reassignment_count')->default(0);
            
            // Versioning
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['merchant_id', 'status', 'created_at']);
            $table->index(['buyer_id', 'status']);
            $table->index(['buyer_phone', 'status']);
            $table->index(['dsp_id', 'status']);
            $table->index(['status', 'expires_at']);
            $table->index('reference_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('peacelinks');
    }
};

// database/migrations/2026_01_07_000005_create_sph_holds_table.php

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sph_holds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('peacelink_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('buyer_wallet_id')->constrained('wallets');
            $table->decimal('amount', 15, 2);
            $table->enum('status', ['active', 'released', 'refunded', 'partial_refund'])->default('active');
            $table->timestamp('released_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
            
            $table->index(['peacelink_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sph_holds');
    }
};

// database/migrations/2026_01_07_000006_create_peacelink_payouts_table.php

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('peacelink_payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('peacelink_id')->constrained()->cascadeOnDelete();
            $table->enum('recipient_type', ['merchant', 'dsp', 'buyer', 'platform']);
            $table->foreignUuid('recipient_id')->nullable()->constrained('users');
            $table->foreignUuid('wallet_id')->nullable()->constrained('wallets');
            $table->decimal('gross_amount', 15, 2);
            $table->decimal('fee_amount', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2);
            $table->enum('payout_type', ['advance', 'final', 'delivery_fee', 'refund', 'platform_fee']);
            $table->boolean('is_advance')->default(false);
            $table->string('notes')->nullable();
            $table->timestamps();
            
            $table->index(['peacelink_id', 'recipient_type']);
            $table->index(['recipient_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('peacelink_payouts');
    }
};

// database/migrations/2026_01_07_000007_create_fee_configurations_table.php

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_configurations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('fee_type', [
                'merchant_percentage', 'merchant_fixed',
                'dsp_percentage', 'advance_percentage',
                'cashout_percentage'
            ]);
            $table->decimal('rate', 8, 5)->nullable(); // For percentages (0.005 = 0.5%)
            $table->decimal('fixed_amount', 10, 2)->nullable(); // For fixed fees
            $table->string('currency', 3)->default('EGP');
            $table->boolean('is_active')->default(true);
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->string('description')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();
            
            $table->index(['fee_type', 'is_active', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_configurations');
    }
};

// database/migrations/2026_01_07_000008_create_cashout_requests_table.php

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashout_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained();
            $table->foreignUuid('wallet_id')->constrained();
            $table->foreignUuid('cashout_method_id')->constrained('cashout_methods');
            $table->decimal('requested_amount', 15, 2);
            $table->decimal('fee_amount', 15, 2);
            $table->decimal('net_amount', 15, 2);
            $table->enum('status', ['pending', 'approved', 'rejected', 'processing', 'completed', 'failed'])->default('pending');
            $table->foreignUuid('processed_by')->nullable()->constrained('users');
            $table->timestamp('processed_at')->nullable();
            $table->string('external_reference')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();
            
            $table->index(['user_id', 'status', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('cashout_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained();
            $table->enum('type', ['bank_transfer', 'vodafone_cash', 'fawry', 'instapay']);
            $table->string('account_name');
            $table->string('account_number');
            $table->string('bank_name')->nullable();
            $table->string('bank_swift')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['user_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashout_requests');
        Schema::dropIfExists('cashout_methods');
    }
};

// database/migrations/2026_01_07_000009_create_disputes_table.php

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('peacelink_id')->constrained();
            $table->foreignUuid('opened_by')->constrained('users');
            $table->enum('opened_by_role', ['buyer', 'merchant', 'dsp']);
            $table->enum('reason', [
                'item_not_received', 'item_damaged', 'item_wrong',
                'item_not_as_described', 'delivery_issue', 'fraud',
                'otp_issue', 'other'
            ]);
            $table->text('description');
            $table->enum('status', ['open', 'under_review', 'resolved', 'escalated', 'closed'])->default('open');
            $table->enum('resolution', [
                'refund_buyer', 'release_merchant', 'partial_refund',
                'release_with_dsp', 'no_action'
            ])->nullable();
            $table->text('resolution_notes')->nullable();
            $table->foreignUuid('resolved_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            
            $table->index(['peacelink_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('dispute_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dispute_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sender_id')->constrained('users');
            $table->enum('sender_role', ['buyer', 'merchant', 'dsp', 'admin']);
            $table->text('message');
            $table->json('attachments')->nullable();
            $table->boolean('is_internal')->default(false);
            $table->timestamps();
            
            $table->index(['dispute_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_messages');
        Schema::dropIfExists('disputes');
    }
};

// database/migrations/2026_01_07_000010_create_ledger_entries_table.php

return new class extends Migration
{
    public function up(): void
    {
        // Append-only immutable ledger
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('peacelink_id')->nullable()->constrained();
            $table->enum('entry_type', [
                'sph_hold', 'sph_release', 'merchant_payout', 'dsp_payout',
                'buyer_refund', 'platform_fee', 'cashout', 'topup', 'adjustment'
            ]);
            $table->foreignUuid('debit_wallet_id')->nullable()->constrained('wallets');
            $table->foreignUuid('credit_wallet_id')->nullable()->constrained('wallets');
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('EGP');
            $table->string('description');
            $table->json('metadata')->nullable();
            $table->string('idempotency_key', 64)->unique();
            $table->timestamp('created_at');
            
            // NO updated_at - immutable
            // NO soft deletes - immutable
            
            $table->index(['peacelink_id', 'entry_type']);
            $table->index(['debit_wallet_id', 'created_at']);
            $table->index(['credit_wallet_id', 'created_at']);
            $table->index('created_at');
        });

        // Prevent modifications trigger (handled in database_schema.sql)
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};

// database/migrations/2026_01_07_000011_create_audit_logs_table.php

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained();
            $table->string('action', 100);
            $table->string('entity_type', 100);
            $table->uuid('entity_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at');
            
            $table->index(['entity_type', 'entity_id']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
