<?php
/**
 * Fee Configuration Table Migration
 * Enables dynamic fee management
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('type')->unique(); // peacelink_merchant, peacelink_dsp, advance_payment, cashout
            $table->decimal('percentage_fee', 5, 2)->default(0);
            $table->decimal('fixed_fee', 10, 2)->default(0);
            $table->decimal('min_fee', 10, 2)->nullable();
            $table->decimal('max_fee', 10, 2)->nullable();
            $table->decimal('min_amount', 10, 2)->nullable();
            $table->decimal('max_amount', 10, 2)->nullable();
            $table->json('applies_to'); // ['merchant', 'dsp', 'buyer']
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed default values
        DB::table('fee_configs')->insert([
            [
                'id' => Str::uuid(),
                'name' => 'رسوم التاجر - PeaceLink',
                'type' => 'peacelink_merchant',
                'percentage_fee' => 1.00,
                'fixed_fee' => 3.00,
                'applies_to' => json_encode(['merchant']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid(),
                'name' => 'رسوم المندوب - PeaceLink',
                'type' => 'peacelink_dsp',
                'percentage_fee' => 0.50,
                'fixed_fee' => 0.00,
                'applies_to' => json_encode(['dsp']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid(),
                'name' => 'رسوم الدفع المقدم',
                'type' => 'advance_payment',
                'percentage_fee' => 1.00,
                'fixed_fee' => 0.00,
                'applies_to' => json_encode(['merchant']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid(),
                'name' => 'رسوم السحب',
                'type' => 'cashout',
                'percentage_fee' => 1.50,
                'fixed_fee' => 0.00,
                'min_amount' => 10.00,
                'applies_to' => json_encode(['merchant', 'dsp', 'buyer']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Fee config history for audit
        Schema::create('fee_config_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('fee_config_id');
            $table->json('old_values');
            $table->json('new_values');
            $table->uuid('changed_by');
            $table->timestamps();

            $table->foreign('fee_config_id')->references('id')->on('fee_configs');
            $table->foreign('changed_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_config_histories');
        Schema::dropIfExists('fee_configs');
    }
};
