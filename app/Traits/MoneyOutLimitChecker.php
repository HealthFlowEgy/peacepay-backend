<?php

namespace App\Traits;

use App\Models\Transaction;
use App\Models\Admin\TransactionSetting;
use App\Constants\PaymentGatewayConst;
use Carbon\Carbon;

trait MoneyOutLimitChecker
{
    /**
     * Check if user has exceeded any periodic limits for money out
     *
     * @param int $userId
     * @param float $amount
     * @param string $currencyCode
     * @return array
     */
    public function checkMoneyOutLimits($userId, $amount, $currencyCode)
    {
        // Get money out transaction settings
        $transactionSetting = TransactionSetting::where('slug', 'money-out')->first();
        
        if (!$transactionSetting) {
            return ['status' => true, 'message' => ''];
        }

        // Check per request limit (already exists but let's verify)
        if ($amount > $transactionSetting->max_limit) {
            return [
                'status' => false,
                'message' => __('You have exceeded the maximum cash out limit per request (:limit :currency). Please try with a smaller amount.', [
                    'limit' => number_format($transactionSetting->max_limit, 2),
                    'currency' => $currencyCode
                ])
            ];
        }

        // Check daily limit
        if ($transactionSetting->daily_limit > 0) {
            $dailyTotal = $this->getDailyMoneyOutTotal($userId, $currencyCode);
            if (($dailyTotal + $amount) > $transactionSetting->daily_limit) {
                return [
                    'status' => false,
                    'message' => __('You have exceeded your daily cash out limit (:limit :currency). Please try again tomorrow or contact support.', [
                        'limit' => number_format($transactionSetting->daily_limit, 2),
                        'currency' => $currencyCode
                    ])
                ];
            }
        }

        // Check weekly limit
        if ($transactionSetting->weekly_limit > 0) {
            $weeklyTotal = $this->getWeeklyMoneyOutTotal($userId, $currencyCode);
            if (($weeklyTotal + $amount) > $transactionSetting->weekly_limit) {
                return [
                    'status' => false,
                    'message' => __('You have exceeded your weekly cash out limit (:limit :currency). Please try again next week or contact support.', [
                        'limit' => number_format($transactionSetting->weekly_limit, 2),
                        'currency' => $currencyCode
                    ])
                ];
            }
        }

        // Check monthly limit
        if ($transactionSetting->monthly_limit > 0) {
            $monthlyTotal = $this->getMonthlyMoneyOutTotal($userId, $currencyCode);
            if (($monthlyTotal + $amount) > $transactionSetting->monthly_limit) {
                return [
                    'status' => false,
                    'message' => __('You have exceeded your monthly cash out limit (:limit :currency). Please try again next month or contact support.', [
                        'limit' => number_format($transactionSetting->monthly_limit, 2),
                        'currency' => $currencyCode
                    ])
                ];
            }
        }

        return ['status' => true, 'message' => ''];
    }

    /**
     * Get total money out amount for today
     *
     * @param int $userId
     * @param string $currencyCode
     * @return float
     */
    private function getDailyMoneyOutTotal($userId, $currencyCode)
    {
        return Transaction::where('user_id', $userId)
            ->where('type', PaymentGatewayConst::TYPEMONEYOUT)
            ->where('sender_currency_code', $currencyCode)
            ->whereIn('status', [PaymentGatewayConst::STATUSSUCCESS, PaymentGatewayConst::STATUSPENDING, PaymentGatewayConst::STATUSWAITING])
            ->whereDate('created_at', Carbon::today())
            ->sum('sender_request_amount');
    }

    /**
     * Get total money out amount for this week (last 7 days)
     *
     * @param int $userId
     * @param string $currencyCode
     * @return float
     */
    private function getWeeklyMoneyOutTotal($userId, $currencyCode)
    {
        return Transaction::where('user_id', $userId)
            ->where('type', PaymentGatewayConst::TYPEMONEYOUT)
            ->where('sender_currency_code', $currencyCode)
            ->whereIn('status', [PaymentGatewayConst::STATUSSUCCESS, PaymentGatewayConst::STATUSPENDING, PaymentGatewayConst::STATUSWAITING])
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->sum('sender_request_amount');
    }

    /**
     * Get total money out amount for this month
     *
     * @param int $userId
     * @param string $currencyCode
     * @return float
     */
    private function getMonthlyMoneyOutTotal($userId, $currencyCode)
    {
        return Transaction::where('user_id', $userId)
            ->where('type', PaymentGatewayConst::TYPEMONEYOUT)
            ->where('sender_currency_code', $currencyCode)
            ->whereIn('status', [PaymentGatewayConst::STATUSSUCCESS, PaymentGatewayConst::STATUSPENDING, PaymentGatewayConst::STATUSWAITING])
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->sum('sender_request_amount');
    }

    /**
     * Get user's current usage for all periods
     *
     * @param int $userId
     * @param string $currencyCode
     * @return array
     */
    public function getUserLimitUsage($userId, $currencyCode)
    {
        return [
            'daily' => $this->getDailyMoneyOutTotal($userId, $currencyCode),
            'weekly' => $this->getWeeklyMoneyOutTotal($userId, $currencyCode),
            'monthly' => $this->getMonthlyMoneyOutTotal($userId, $currencyCode),
        ];
    }
}
