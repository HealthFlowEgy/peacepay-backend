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
        Schema::table('escrows', function (Blueprint $table) {
            // Store actual fee values, not tier IDs
            $table->decimal('delivery_tier_fixed_charge', 28, 8)->default(0)->after('delivery_id');
            $table->decimal('delivery_tier_percent_charge', 28, 8)->default(0)->after('delivery_tier_fixed_charge');
            $table->decimal('merchant_tier_fixed_charge', 28, 8)->default(0)->after('delivery_tier_percent_charge');
            $table->decimal('merchant_tier_percent_charge', 28, 8)->default(0)->after('merchant_tier_fixed_charge');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('escrows', function (Blueprint $table) {
            $table->dropColumn([
                'delivery_tier_fixed_charge',
                'delivery_tier_percent_charge',
                'merchant_tier_fixed_charge',
                'merchant_tier_percent_charge'
            ]);
        });
    }
};
