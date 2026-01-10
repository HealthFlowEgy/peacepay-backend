<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Fee Configurations Table
 * Stores configurable fee rates for the platform
 * Based on Re-Engineering Specification v2.0
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fee_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('fee_type', 50)->unique(); // merchant_percentage, merchant_fixed, dsp_percentage, etc.
            $table->string('name', 100);
            $table->string('name_ar', 100)->nullable();
            $table->text('description')->nullable();
            $table->decimal('rate', 8, 4)->default(0); // Percentage as decimal (0.005 = 0.5%)
            $table->decimal('fixed_amount', 10, 2)->default(0);
            $table->decimal('min_amount', 10, 2)->nullable();
            $table->decimal('max_amount', 10, 2)->nullable();
            $table->string('currency', 3)->default('EGP');
            $table->boolean('is_active')->default(true);
            $table->timestamp('effective_from')->useCurrent();
            $table->timestamp('effective_to')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            
            $table->index('fee_type');
            $table->index('is_active');
        });

        // Insert default fee configurations
        DB::table('fee_configurations')->insert([
            [
                'fee_type' => 'merchant_percentage',
                'name' => 'Merchant Transaction Fee (Percentage)',
                'name_ar' => 'رسوم معاملة التاجر (نسبة)',
                'description' => 'Percentage fee charged to merchant on item amount',
                'rate' => 0.005, // 0.5%
                'fixed_amount' => 0,
                'currency' => 'EGP',
                'is_active' => true,
                'effective_from' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'fee_type' => 'merchant_fixed',
                'name' => 'Merchant Transaction Fee (Fixed)',
                'name_ar' => 'رسوم معاملة التاجر (ثابتة)',
                'description' => 'Fixed fee charged to merchant on FINAL release only (not on advance)',
                'rate' => 0,
                'fixed_amount' => 2.00, // 2 EGP
                'currency' => 'EGP',
                'is_active' => true,
                'effective_from' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'fee_type' => 'dsp_percentage',
                'name' => 'DSP Transaction Fee',
                'name_ar' => 'رسوم معاملة مزود التوصيل',
                'description' => 'Percentage fee charged to DSP on delivery fee',
                'rate' => 0.005, // 0.5%
                'fixed_amount' => 0,
                'currency' => 'EGP',
                'is_active' => true,
                'effective_from' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'fee_type' => 'cashout_percentage',
                'name' => 'Cash-out Fee',
                'name_ar' => 'رسوم السحب',
                'description' => 'Percentage fee charged on cash-out requests (deducted at request time)',
                'rate' => 0.015, // 1.5%
                'fixed_amount' => 0,
                'currency' => 'EGP',
                'is_active' => true,
                'effective_from' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'fee_type' => 'advance_percentage',
                'name' => 'Advance Payment Fee',
                'name_ar' => 'رسوم الدفعة المقدمة',
                'description' => 'Percentage fee on advance payment (no fixed fee)',
                'rate' => 0.005, // 0.5%
                'fixed_amount' => 0,
                'currency' => 'EGP',
                'is_active' => true,
                'effective_from' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fee_configurations');
    }
};
