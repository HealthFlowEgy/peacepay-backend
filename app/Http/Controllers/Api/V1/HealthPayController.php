<?php

namespace App\Http\Controllers\Api\V1;

use App\Constants\EscrowConstants;
use App\Constants\NotificationConst;
use App\Constants\PaymentGatewayConst;
use App\Http\Controllers\Controller;
use App\Models\Admin\BasicSettings;
use App\Models\Admin\Currency;
use App\Models\Admin\PaymentGateway;
use App\Models\Escrow;
use App\Models\TemporaryData;
use App\Models\Transaction;
use App\Models\UserNotification;
use App\Models\UserWallet;
use App\Notifications\Escrow\EscrowApprovel;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Softonic\GraphQL\ClientBuilder;
use App\Events\User\NotificationEvent as UserNotificationEvent;
use Illuminate\Support\Facades\Log;

class HealthPayController extends Controller
{
    use \App\Traits\PaymentGateway\HealthPay;

    public function verifyMobile(Request $request)
    {
        $request->validate([
            'otp' => 'required',
            'amount' => 'required|numeric',
            'trx' => 'required|string',
        ]);

        $tempData = TemporaryData::where('identifier', $request->trx)->first();

        $output['gateway'] = PaymentGateway::where('alias', 'healthpay')->first();
        $credentials = $this->getCredentialsHealthPay($output);

        $clientUser = ClientBuilder::build($credentials['baseURL'], [
            'headers' => [
                'api-header' => $credentials['apiHeader'],
                'Authorization' => 'Bearer ' . $tempData['data']->merchantToken,
            ],
        ]);



        $deductAmount = (float) $request->amount;
        $amount = $deductAmount;
        $topupWalletUser = $this->topupWalletUser($clientUser, $amount, 1);
        return response()->json([
            'iframeUrl' => $topupWalletUser['iframeUrl'],
            'message' => 'Please top up your wallet to proceed with the escrow payment.',
        ]);
        
    }


    public function escrowWalletPayment($escrowData)
    {
        $sender_currency = Currency::where('code', $escrowData->escrow_currency)->first();
        $user_wallet     = UserWallet::where(['user_id' => auth()->user()->id, 'currency_id' => $sender_currency->id])->first();
        //buyer amount calculation
        $amount = $escrowData->amount;
        if ($escrowData->role == EscrowConstants::SELLER_TYPE && $escrowData->who_will_pay == EscrowConstants::ME) {
            $buyer_amount = $amount;
        } else if ($escrowData->role == EscrowConstants::SELLER_TYPE && $escrowData->who_will_pay == EscrowConstants::BUYER) {
            $buyer_amount = $amount + $escrowData->escrowDetails->fee;
        } else if ($escrowData->role == EscrowConstants::SELLER_TYPE && $escrowData->who_will_pay == EscrowConstants::HALF) {
            $buyer_amount = $amount + ($escrowData->escrowDetails->fee / 2);
        }
        $user_wallet->balance -= $buyer_amount;
        $escrowData->escrowDetails->buyer_pay = $buyer_amount;

        DB::beginTransaction();
        try {
            // $user_wallet->save();
            $escrowData->escrowDetails->save();
            DB::commit();
            $this->approvelNotificationSend($escrowData->user, $escrowData);
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception($e->getMessage());
        }
    }

    public function approvelNotificationSend($user, $escrow)
    {
        $notification_content = [
            'title'   => "Escrow Approvel Payment",
            'message' => "A user has paid your escrow",
            'time'    => Carbon::now()->diffForHumans(),
            'image'   => files_asset_path('profile-default'),
        ];
        UserNotification::create([
            'type'    => NotificationConst::ESCROW_CREATE,
            'user_id' => $user->id,
            'message' => $notification_content,
        ]);
        //Push Notifications
        $basic_setting = BasicSettings::first();
        try {
            try {
                $user->notify(new EscrowApprovel($user, $escrow));
            } catch (\Exception $e) {
                \Log::info('error mail : ,' . $e->getMessage());
            }
            if ($basic_setting->push_notification == true) {
                event(new UserNotificationEvent($notification_content, $user));
                send_push_notification(["user-" . $user->id], [
                    'title'     => $notification_content['title'],
                    'body'      => $notification_content['message'],
                    'icon'      => $notification_content['image'],
                ]);
            }
        } catch (Exception $e) {
        }
    }



    public function callback(Request $request)
    {
        try {
            Log::info('callback log');
            Log::info($request->all());

            $body = $request->all();
            $temporaryData = TemporaryData::where('identifier', $body['transaction_id'])->first();

            $secretSignature = env('PAYMENT_SECRET_SIGNATURE', 'merchant_Peacepay_key_peacepay');

            $receivedSignature = $request->header('X-Signature') ?? $request->input('signature');

            $bodyForValidation = $body;
            unset($bodyForValidation['signature']);

            $concatenatedValues = '';
            foreach ($bodyForValidation as $key => $value) {
                $concatenatedValues .= $value;
            }

            $stringToHash = $concatenatedValues . $secretSignature;

            $calculatedSignature = hash('sha256', $stringToHash);

            if (!hash_equals($calculatedSignature, $receivedSignature)) {
                Log::warning('Invalid signature in payment callback', [
                    'received_signature' => $receivedSignature,
                    'calculated_signature' => $calculatedSignature,
                    'body' => $body
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid signature'
                ], 400);
            }

            // Signature is valid, process the payment callback
            $orderId = $body['order_id'] ?? null;
            $amount = $body['amount'] ?? null;
            $status = $body['status'] ?? null;
            $transactionId = $body['transaction_id'] ?? null;
            $uuid = $body['uuid'] ?? null;

            DB::beginTransaction();
            try {
                $temporaryData = TemporaryData::where('identifier', $transactionId)->first();

                if ($temporaryData) {
                    $userId = $temporaryData->data->user_id;
                    $userWallet = UserWallet::where('user_id', $userId)->first();

                    if (!$userWallet) {
                        throw new Exception('User wallet not found');
                    }

                    // Get amounts from temporary data
                    $requestedAmount = $temporaryData->data->amount->requested_amount ?? $amount;
                    $totalPayable = $temporaryData->data->amount->total_payable_amount ?? $amount;
                    $totalCharge = $temporaryData->data->amount->gateway_total_charge ?? 0;

                    // Get previous balance before update
                    $previousBalance = $userWallet->balance;

                    // Generate unique transaction ID
                    $trx_id = 'HP' . getTrxNum();

                    // Update wallet balance if payment was successful
                    // Add only the requested amount (500), not the total payable (506)
                    if ($status) {
                        $userWallet->balance += $requestedAmount;
                        $userWallet->save();
                    }

                    // Create transaction record
                    Transaction::create([
                        'user_id' => $userId,
                        'user_wallet_id' => $userWallet->id,
                        'payment_gateway_currency_id' => $temporaryData->data->currency ?? null,
                        'trx_id' => $trx_id,
                        'sender_request_amount' => $requestedAmount,
                        'total_payable' => $totalPayable,
                        'available_balance' => $userWallet->balance,
                        'exchange_rate' => $temporaryData->data->amount->exchange_rate ?? 1,
                        'remark' => 'Add Money via HealthPay',
                        'details' => json_encode([
                            'order_id' => $orderId,
                            'transaction_id' => $transactionId,
                            'uuid' => $uuid,
                            'payment_method' => 'HealthPay',
                            'previous_balance' => $previousBalance,
                            'new_balance' => $userWallet->balance,
                            'requested_amount' => $requestedAmount,
                            'total_payable' => $totalPayable,
                            'total_charge' => $totalCharge,
                        ]),
                        'type' => PaymentGatewayConst::TYPEADDMONEY,
                        'status' => $status ? PaymentGatewayConst::STATUSSUCCESS : PaymentGatewayConst::STATUSREJECTED,
                        'sender_currency_code' => $userWallet->currency->code ?? null,
                    ]);

                    // Delete temporary data
                    $temporaryData->delete();

                    Log::info('Transaction created successfully', [
                        'trx_id' => $trx_id,
                        'user_id' => $userId,
                        'requested_amount' => $requestedAmount,
                        'total_payable' => $totalPayable,
                        'total_charge' => $totalCharge,
                        'status' => $status
                    ]);
                }

                DB::commit();
            } catch (Exception $e) {
                DB::rollback();
                throw $e;
            }


            // Return success response
            return response()->json([
                'status' => 'success',
                'message' => 'Callback processed successfully'
            ], 200);
        } catch (\Exception $e) {
            \Log::error('Error processing payment callback: ' . $e->getMessage(), [
                'body' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error'
            ], 500);
        }
    }

    // Helper method to validate signature (alternative approach)
    private function validateSignature($body, $receivedSignature, $secretSignature)
    {
        // Remove signature from body if present
        $bodyForValidation = $body;
        unset($bodyForValidation['signature']);

        // Concatenate values
        $concatenatedValues = implode('', array_values($bodyForValidation));

        // Create hash
        $calculatedSignature = hash('sha256', $concatenatedValues . $secretSignature);

        return hash_equals($calculatedSignature, $receivedSignature);
    }
}
