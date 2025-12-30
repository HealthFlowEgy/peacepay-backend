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
        Schema::table('escrow_details', function (Blueprint $table) {
            $table->decimal('merchant_fees', 28, 8)->default(0)->after('fee');
            $table->decimal('delivery_fees', 28, 8)->default(0)->after('merchant_fees');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('escrow_details', function (Blueprint $table) {
            $table->dropColumn(['merchant_fees', 'delivery_fees']);
        });
    }
};
