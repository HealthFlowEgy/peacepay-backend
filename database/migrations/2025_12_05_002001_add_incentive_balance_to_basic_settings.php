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
        Schema::table('basic_settings', function (Blueprint $table) {
            $table->decimal('incentive_balance_seller', 28, 8)->default(0)->after('kyc_verification');
            $table->decimal('incentive_balance_buyer', 28, 8)->default(0)->after('incentive_balance_seller');
            $table->decimal('incentive_balance_delivery', 28, 8)->default(0)->after('incentive_balance_buyer');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('basic_settings', function (Blueprint $table) {
            $table->dropColumn(['incentive_balance_seller', 'incentive_balance_buyer', 'incentive_balance_delivery']);
        });
    }
};
