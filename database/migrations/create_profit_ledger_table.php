<?php
/**
 * Profit Ledger Table Migration
 * Detailed tracking of all fee earnings
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profit_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('transaction_id')->nullable();
            $table->string('transaction_type'); // peacelink, cashout, advance
            $table->uuid('peacelink_id')->nullable();
            $table->string('fee_type'); // merchant_fee, dsp_fee, advance_fee, cashout_fee
            $table->decimal('amount', 12, 2);
            $table->decimal('base_amount', 12, 2);
            $table->decimal('percentage_applied', 5, 2);
            $table->decimal('fixed_fee_applied', 10, 2);
            $table->uuid('party_id'); // User ID of the party charged
            $table->string('party_role'); // merchant, dsp, buyer
            $table->uuid('fee_config_id')->nullable();
            $table->timestamps();

            $table->index(['created_at']);
            $table->index(['fee_type']);
            $table->index(['party_id']);
        });

        // Create view for daily/weekly/monthly summaries
        DB::statement("
            CREATE VIEW profit_summary AS
            SELECT
                DATE(created_at) as date,
                fee_type,
                SUM(amount) as total_amount,
                COUNT(*) as transaction_count
            FROM profit_ledger
            GROUP BY DATE(created_at), fee_type
        ");
    }

    public function down(): void
    {
        DB::statement("DROP VIEW IF EXISTS profit_summary");
        Schema::dropIfExists('profit_ledger');
    }
};
