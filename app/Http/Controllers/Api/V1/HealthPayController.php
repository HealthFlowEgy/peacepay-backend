<?php

namespace App\Http\Controllers\Api\V1;

use App\Constants\EscrowConstants;
use App\Constants\NotificationConst;
use App\Http\Controllers\Controller;
use App\Models\Admin\BasicSettings;
use App\Models\Admin\Currency;
use App\Models\Admin\PaymentGateway;
use App\Models\Escrow;
use App\Models\TemporaryData;
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
            'mobile' => 'required|numeric',
            'amount' => 'required|numeric',
            'trx' => 'required|string',
        ]);

        $tempData = TemporaryData::where('identifier', $request->trx)->first();


        $query = '
           mutation authUser(
               $mobile: String!
               $otp: String!
               $isProvider: Boolean!
           ) {
               authUser(
                   mobile: $mobile
                   otp: $otp
                   isProvider: $isProvider
               ) {
                   userToken
                   user {
                       uid
                   }
               }
           }
       ';


        $mobileHealthPay = formatMobileNumber($request['mobile']);
        $output['gateway'] = PaymentGateway::where('alias', 'healthpay')->first();
        $credentials = $this->getCredentialsHealthPay($output);

        $clientUser = ClientBuilder::build($credentials['baseURL'], [
            'headers' => [
                'api-header' => $credentials['apiHeader'],
                'Authorization' => 'Bearer ' . $tempData['data']->merchantToken,
            ],
        ]);

        $response = $clientUser->query($query, [
            'mobile'     => $mobileHealthPay,
            'otp'        => $request['otp'],
            'isProvider' => false,
        ]);

        if ($response->hasErrors()) {
            throw new \Exception('Error authenticating user: ' . json_encode($response->getErrors()));
        }

        $userToken = $response->getData()['authUser']['userToken'];
        session()->put('userTokenHealthPay', $userToken);


        $userHealthpayWallet = getUserHealthpayWallet($userToken, $output['gateway']);

        $deductAmount = (float) $request->amount;
        if ($deductAmount > (float) $userHealthpayWallet['total']) {
            $amount = $deductAmount - (float) $userHealthpayWallet['total'];
            $topupWalletUser = $this->topupWalletUser($clientUser, $amount);
            return response()->json([
                'iframeUrl' => $topupWalletUser['iframeUrl'],
                'message' => 'Please top up your wallet to proceed with the escrow payment.',
            ]);
        } else {
            // $id = session()->get('escrowId');
            // $escrow    = Escrow::findOrFail($id);

            // $this->escrowWalletPayment($escrow);
            // $escrow->payment_type = EscrowConstants::GATEWAY;
            // $escrow->status       = EscrowConstants::ONGOING;

            // deductFromUser($userToken, $deductAmount , 'deduct ' . $deductAmount,$output['gateway']);

            // $escrow->save();

            // return redirect()->route('user.my-escrow.index', [
            //     'id' => $escrow->id,
            // ])->with([
            //     'success' => ['Payment successful'],
            // ]);
        }
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

            // Get the request body data
            $body = $request->all();

            // Your secret signature key (store this securely, preferably in environment variables)
            $secretSignature = env('PAYMENT_SECRET_SIGNATURE', 'merchant_04Bgnehn45_k_0003BgNehneM');

            // Extract the signature from the request (assuming it's sent separately or in headers)
            $receivedSignature = $request->header('X-Signature') ?? $request->input('signature');

            // Step 1: Collect all values from the body object
            // Remove the signature field if it's included in the body to avoid circular validation
            $bodyForValidation = $body;
            unset($bodyForValidation['signature']);

            // Step 2: Concatenate all values in the order they appear
            $concatenatedValues = '';
            foreach ($bodyForValidation as $key => $value) {
                $concatenatedValues .= $value;
            }

            // Step 3: Add the secret signature to the concatenated string
            $stringToHash = $concatenatedValues . $secretSignature;

            // Step 4 & 5: Hash the string using SHA-256 and convert to hex
            $calculatedSignature = hash('sha256', $stringToHash);

            // Validate the signature
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

            // Update your order/payment record based on the callback data
            if ($orderId && $status) {
                // Find and update the order
                $order = Order::where('order_id', $orderId)->first();

                if ($order) {
                    $order->update([
                        'payment_status' => $status,
                        'transaction_id' => $transactionId,
                        'payment_uuid' => $uuid,
                        'payment_amount' => $amount,
                        'updated_at' => now()
                    ]);

                    // Handle different payment statuses
                    switch (strtoupper($status)) {
                        case 'SUCCESS':
                            // Payment successful - mark order as paid
                            $order->markAsPaid();
                            \Log::info('Payment successful for order: ' . $orderId);
                            break;

                        case 'FAILED':
                            // Payment failed - mark order as failed
                            $order->markAsFailed();
                            \Log::info('Payment failed for order: ' . $orderId);
                            break;

                        case 'PENDING':
                            // Payment pending - keep as pending
                            $order->markAsPending();
                            \Log::info('Payment pending for order: ' . $orderId);
                            break;

                        default:
                            \Log::warning('Unknown payment status: ' . $status . ' for order: ' . $orderId);
                    }

                    // Send notifications, emails, etc. based on payment status
                    // $this->sendPaymentNotification($order, $status);

                } else {
                    \Log::warning('Order not found for callback', ['order_id' => $orderId]);
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Order not found'
                    ], 404);
                }
            }

            // Log successful callback processing
            \Log::info('Payment callback processed successfully', [
                'order_id' => $orderId,
                'status' => $status,
                'transaction_id' => $transactionId
            ]);

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
