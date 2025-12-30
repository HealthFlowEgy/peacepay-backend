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
            $table->decimal('delivery_get', 28, 8)->default(0)->after('seller_get');
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
            $table->dropColumn('delivery_get');
        });
    }
};
