<?php

namespace App\Traits\User;

use App\Models\Admin\Currency;
use App\Models\Admin\BasicSettings;
use App\Models\UserWallet;
use Exception;

trait RegisteredUsers {
    protected function createUserWallets($user) {
        $currencies = Currency::active()->roleHasOne()->pluck("id")->toArray();
        $basic_settings = BasicSettings::first();

        // Get incentive balance based on user type (only for seller/buyer, not delivery)
        // Delivery users receive incentive when they complete release payments
        $incentiveBalance = 0;
        if ($basic_settings) {
            switch ($user->type) {
                case 'seller':
                    $incentiveBalance = $basic_settings->incentive_balance_seller ?? 0;
                    break;
                case 'buyer':
                    $incentiveBalance = $basic_settings->incentive_balance_buyer ?? 0;
                    break;
                case 'delivery':
                    // Delivery users don't get incentive on registration
                    // They receive it when completing release payments
                    $incentiveBalance = 0;
                    break;
                default:
                    $incentiveBalance = 0;
            }
        }

        $wallets = [];
        foreach($currencies as $currency_id) {
            $wallets[] = [
                'user_id'       => $user->id,
                'currency_id'   => $currency_id,
                'balance'       => $incentiveBalance,
                'status'        => true,
                'created_at'    => now(),
            ];
        }
        try{
            UserWallet::insert($wallets);
        }catch(Exception $e) {
            // handle error
            $this->guard()->logout();
            $user->delete();
            return $this->breakAuthentication("Failed to create wallet! Please try again");
        }
    }


    protected function breakAuthentication($error) {
        return back()->with(['error' => [$error]]);
    }
}