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
        Schema::create('escrow_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('escrow_id');
            $table->unsignedBigInteger('policy_id');
            $table->float('fee')->default(0);
            $table->string('field');
            $table->string('collected_from');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('escrow_policies');
    }
};
