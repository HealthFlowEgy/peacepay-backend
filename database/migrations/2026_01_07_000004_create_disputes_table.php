<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Disputes Table
 * Handles dispute resolution for PeaceLink transactions
 * Based on Re-Engineering Specification v2.0
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->uuid('dispute_id')->unique();
            $table->unsignedBigInteger('escrow_id');
            $table->unsignedBigInteger('opened_by');
            $table->string('opened_by_role', 20); // buyer, merchant, dsp
            $table->string('status', 30)->default('open'); // open, under_review, resolved_buyer, resolved_merchant, resolved_split
            $table->text('reason');
            $table->text('reason_ar')->nullable();
            $table->json('evidence_urls')->nullable();
            
            // Resolution details
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_type', 50)->nullable(); // refund_buyer, release_merchant, split, other
            $table->text('resolution_notes')->nullable();
            $table->decimal('buyer_amount', 15, 2)->nullable();
            $table->decimal('merchant_amount', 15, 2)->nullable();
            $table->decimal('dsp_amount', 15, 2)->nullable();
            
            $table->timestamps();
            
            $table->index('escrow_id');
            $table->index('status');
            $table->index('opened_by');
            
            $table->foreign('escrow_id')->references('id')->on('escrows')->onDelete('cascade');
            $table->foreign('opened_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('resolved_by')->references('id')->on('users')->onDelete('set null');
        });

        // Dispute messages table
        Schema::create('dispute_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dispute_id');
            $table->unsignedBigInteger('sender_id');
            $table->text('message');
            $table->json('attachments')->nullable();
            $table->boolean('is_admin_only')->default(false); // Internal admin notes
            $table->timestamp('created_at')->useCurrent();
            
            $table->index('dispute_id');
            
            $table->foreign('dispute_id')->references('id')->on('disputes')->onDelete('cascade');
            $table->foreign('sender_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dispute_messages');
        Schema::dropIfExists('disputes');
    }
};
