<?php
/**
 * KYC Submissions Table Migration
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->enum('current_level', ['bronze', 'silver', 'gold']);
            $table->enum('requested_level', ['silver', 'gold']);
            $table->enum('status', ['pending', 'under_review', 'approved', 'rejected'])->default('pending');
            $table->json('documents'); // Array of document info
            $table->json('personal_info'); // Name, national ID, DOB, address
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('reviewed_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_submissions');
    }
};
