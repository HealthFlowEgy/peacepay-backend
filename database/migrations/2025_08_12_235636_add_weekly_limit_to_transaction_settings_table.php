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
        Schema::table('transaction_settings', function (Blueprint $table) {
            $table->decimal('weekly_limit', 28, 8, true)->default(0)->after('daily_limit');
            // $table->decimal('monthly_limit', 28, 8, true)->default(0)->after('weekly_limit');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('transaction_settings', function (Blueprint $table) {
            $table->dropColumn(['weekly_limit'
            // , 'monthly_limit'
        ]);
        });
    }
};
