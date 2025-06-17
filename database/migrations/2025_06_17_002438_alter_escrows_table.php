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
            $table->float('total_amount_get_for_all_users')->default(0);
            $table->float('amount_get_for_seller')->default(0);
            $table->float('amount_get_for_buyer')->default(0);
            $table->float('amount_get_for_delivery')->default(0);
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
            $table->dropColumn('total_amount_get_for_all_users');
            $table->dropColumn('amount_get_for_seller');
            $table->dropColumn('amount_get_for_buyer');
            $table->dropColumn('amount_get_for_delivery');
        });
    }
};
