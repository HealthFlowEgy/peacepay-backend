<?php

namespace Database\Seeders;

use App\Models\Admin\TransactionSetting;
use Illuminate\Database\Seeder;

class AdditionalToServerSeeder extends Seeder
{
    public function run()
    {
        $transaction_settings = [
            'admin_id' => '1',
            'slug' => 'delivery_fees',
            'title' => 'Delivery Fees',
            'fixed_charge' => '0',
            'percent_charge' => '0.5',
            // 'min_limit' => '1.00',
            // 'max_limit' => '500000.00',
            // 'monthly_limit' => '50000000.00',
            // 'daily_limit' => '500000.00',
            'status' => '1'
        ];

        TransactionSetting::updateOrCreate([
            'slug' => 'delivery_fees'
        ], $transaction_settings);


        $transaction_settings = [
            'admin_id' => '1',
            'slug' => 'advanced_payment_fees',
            'title' => 'Advanced Payment Fees',
            'fixed_charge' => '0',
            'percent_charge' => '0.5',
            // 'min_limit' => '1.00',
            // 'max_limit' => '500000.00',
            // 'monthly_limit' => '50000000.00',
            // 'daily_limit' => '500000.00',
            'status' => '1'
        ];

        TransactionSetting::updateOrCreate([
            'slug' => 'advanced_payment_fees'
        ], $transaction_settings);
    }
}
