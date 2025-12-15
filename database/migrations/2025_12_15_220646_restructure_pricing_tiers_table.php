<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('pricing_tiers', function (Blueprint $table) {
            // Remove old tier-specific columns
            $table->dropColumn([
                'delivery_fixed_charge',
                'delivery_percent_charge',
                'merchant_fixed_charge',
                'merchant_percent_charge',
                'cash_out_fixed_charge',
                'cash_out_percent_charge'
            ]);

            // Add type field and generic charge fields
            $table->enum('type', ['delivery', 'merchant', 'cash_out'])->after('description');
            $table->decimal('fixed_charge', 28, 8)->default(0)->after('type');
            $table->decimal('percent_charge', 28, 8)->default(0)->after('fixed_charge');
        });

        // Drop the pricing_tier_id from users table (we'll use pivot table instead)
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['pricing_tier_id']);
            $table->dropColumn('pricing_tier_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('pricing_tiers', function (Blueprint $table) {
            // Restore old columns
            $table->dropColumn(['type', 'fixed_charge', 'percent_charge']);

            $table->decimal('delivery_fixed_charge', 28, 8)->default(0);
            $table->decimal('delivery_percent_charge', 28, 8)->default(0);
            $table->decimal('merchant_fixed_charge', 28, 8)->default(0);
            $table->decimal('merchant_percent_charge', 28, 8)->default(0);
            $table->decimal('cash_out_fixed_charge', 28, 8)->default(0);
            $table->decimal('cash_out_percent_charge', 28, 8)->default(0);
        });

        // Restore pricing_tier_id to users
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('pricing_tier_id')->nullable();
            $table->foreign('pricing_tier_id')->references('id')->on('pricing_tiers')->onDelete('set null');
        });
    }
};
