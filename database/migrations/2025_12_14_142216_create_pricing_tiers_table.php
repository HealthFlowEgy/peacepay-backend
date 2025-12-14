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
        Schema::create('pricing_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();

            // Delivery fees
            $table->decimal('delivery_fixed_charge', 28, 8)->default(0);
            $table->decimal('delivery_percent_charge', 28, 8)->default(0);

            // Merchant fees
            $table->decimal('merchant_fixed_charge', 28, 8)->default(0);
            $table->decimal('merchant_percent_charge', 28, 8)->default(0);

            // Cash out fees
            $table->decimal('cash_out_fixed_charge', 28, 8)->default(0);
            $table->decimal('cash_out_percent_charge', 28, 8)->default(0);

            $table->boolean('status')->default(true);
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
        Schema::dropIfExists('pricing_tiers');
    }
};
