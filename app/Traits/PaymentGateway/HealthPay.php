<?php

namespace App\Traits\PaymentGateway;

use App\Constants\NotificationConst;
use App\Constants\PaymentGatewayConst;
use App\Http\Helpers\PaymentGateway;
use App\Models\Admin\BasicSettings;
use App\Models\TemporaryData;
use App\Models\UserNotification;
use App\Notifications\User\AddMoney\ApprovedMail;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Auth;
use Softonic\GraphQL\ClientBuilder;
use Illuminate\Support\Facades\DB;
use App\Events\User\NotificationEvent as UserNotificationEvent;
use App\Models\Admin\AdminNotification;
use App\Models\Admin\Currency;
use App\Models\Admin\PaymentGateway as AdminPaymentGateway;
use App\Models\UserWallet;
use Illuminate\Support\Facades\Log;

trait HealthPay
{
    public $baseURL;
    public $apiHeader;
    public $apiKey;


    public function getCredentialsHealthPay($output)
    {
        $output = (array) $output;
        if(!isset($output['gateway'])){
            $output['gateway'] = $output['gateway_currency']->gateway;
        }
        $gateway = $output['gateway'];
        $credentials = ['base-url'];
        $baseURL     = PaymentGateway::getValueFromGatewayCredentials($gateway, $credentials);

        $credentials = ['api-header'];
        $apiHeader     = PaymentGateway::getValueFromGatewayCredentials($gateway, $credentials);

        $credentials = ['api-key'];
        $apiKey     = PaymentGateway::getValueFromGatewayCredentials($gateway, $credentials);

        return [
            'baseURL' => $baseURL,
            'apiHeader' => $apiHeader,
            'apiKey' => $apiKey
        ];
    }
    public function getStaticClient($baseURL, $apiHeader)
    {
        $client = ClientBuilder::build($baseURL, [
            'headers' => [
                'api-header' => $apiHeader,
            ],
        ]);

        return $client;
    }

    public function getStaticClientUser($baseURL, $apiHeader, $merchantToken)
    {
        session()->put('merchantTokenHealthPay', $merchantToken);
        $client = ClientBuilder::build($baseURL, [
            'headers' => [
                'api-header' => $apiHeader,
                'Authorization' => 'Bearer ' . $merchantToken,
            ],
        ]);

        return $client;
    }


    public function getHealthPayMerchantToken(){
        return env('MERCHANT_TOKEN');
        $credentials = $this->getCredentialsHealthPay($output);
        $client = $this->getStaticClient($credentials['baseURL'], $credentials['apiHeader']);

        $response = $client->query('
            mutation authMerchant($apiKey: String!) {
                authMerchant(apiKey: $apiKey) {
                    token
                }
            }
        ', [
            'apiKey' => $credentials['apiKey']
        ]);

        if ($response->hasErrors()) {
            throw new \Exception('Error fetching merchant token: ' . json_encode($response->getErrors()));
        }

        $merchantToken = $response->getData()['authMerchant']['token'];
        return $merchantToken;
    }
    
    public function healthPayInit($output = null)
    {
        $credentials = $this->getCredentialsHealthPay($output);
        $merchantToken = $this->getHealthPayMerchantToken();

        $clientUser = $this->getStaticClientUser($credentials['baseURL'], $credentials['apiHeader'], $merchantToken);

        $loginUser = $this->loginUser($output, $clientUser);

        return $loginUser;
    }


    public function healthPayInitApi($output = null)
    {
        $credentials = $this->getCredentialsHealthPay($output);
        $client = $this->getStaticClient($credentials['baseURL'], $credentials['apiHeader']);

        $response = $client->query('
            mutation authMerchant($apiKey: String!) {
                authMerchant(apiKey: $apiKey) {
                    token
                }
            }
        ', [
            'apiKey' => $credentials['apiKey']
        ]);

        if ($response->hasErrors()) {
            throw new \Exception('Error fetching merchant token: ' . json_encode($response->getErrors()));
        }

        $merchantToken = $response->getData()['authMerchant']['token'];

        $clientUser = $this->getStaticClientUser($credentials['baseURL'], $credentials['apiHeader'], $merchantToken);

        $loginUser = $this->loginUser($output, $clientUser,true,$merchantToken);

        return $loginUser;
    }

    public function loginUser($output, $clientUser, $api = false,$merchantToken = null)
    {
        $output = (array) $output;
        if(!isset($output['wallet'])){
            $userWallet = UserWallet::where([
                'user_id'     => auth()->user()->id,
                'currency_id' => Currency::where('code' ,'EGP')->first()->id
            ])->first();
            $output['wallet'] = $userWallet;
        }
        $user = $output['wallet']->user;
        $mobile = formatMobileNumber($user['full_mobile']);
        
        if($api){
            $data = [
                'currency' => $output['wallet']->currency->id,
                'gateway' => $output['gateway']->id,
                'mobile'    => $mobile,
                'firstName' => $user['firstname'] ?? '',
                'lastName'  => $user['lastname'] ?? '',
                'email'     => $user['email'] ?? '',
                'amount' => $output['amount'],
                'merchantToken' => $merchantToken,
            ];
            $ident = getTrxNum(10);
            TemporaryData::create([
                'type'       => PaymentGatewayConst::HEALTHPAY,
                'identifier' => $ident,
                'data'       => $data,
            ]);
            return [
                'data' => $data,
                'temp_identifier' => $ident,
                'gateway_alias' => 'healthpay',
                'message' => 'User logged in successfully.',
            ];
        }

        return redirect()->route('user.healthpay.showConfirmMobile');
    }



    public function topupWalletUser($clientUser, $amount = 0, $fromMerchant = 0, $amountData = null, $gatewayDetails = [])
    {
        $query = '
                mutation topupWalletUser($userToken: String!, $amount: Float!) {
                    topupWalletUser(
                        userToken: $userToken,
                        amount: $amount
                    ) {
                        uid
                        iframeUrl
                    }
                }
        ';

        $response = $clientUser->query($query, [
            'userToken'     => env('MERCHANT_USER_TOKEN'),
            'amount'        => $amount ?? (float) session()->get('topupAmountHealthPay')
        ]);

        if ($response->hasErrors()) {
            throw new \Exception('Error topupWalletUser: ' . json_encode($response->getErrors()));
        }

        // Prepare data to save in temporary data
        $tempData = array_merge($response->getData()['topupWalletUser'], [
            'user_id' => auth()->user()->id
        ]);

        // Add amount details if provided
        if ($amountData) {
            $tempData['amount'] = $amountData;
        }

        // Add gateway details (payment_gateway_currency_id, etc.)
        if (!empty($gatewayDetails)) {
            $tempData = array_merge($tempData, $gatewayDetails);
        }

        TemporaryData::create([
            'type'       => PaymentGatewayConst::HEALTHPAY,
            'identifier' => $response->getData()['topupWalletUser']['uid'],
            'data'       => $tempData,
        ]);

        return $response->getData()['topupWalletUser'];
    }


    public function healthpaySuccess($output = null)
    {
        if (!$output) $output = $this->output;
        $token            = $this->output['tempData']['identifier'] ?? "";

        return $this->healthPayPaymentCaptured($output);
    }

    public function healthPayPaymentCaptured($output)
    {
        // payment successfully captured record saved to database
        try {
            $trx_id = 'AM' . getTrxNum();
            $user = auth()->user();
            $this->createTransactionHealthPay($output, $trx_id);
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
        return true;
    }

    public function createTransactionHealthPay($output, $trx_id)
    {
        $basic_setting = BasicSettings::first();
        $user = auth()->user();
        $trx_id      = $trx_id;
        $inserted_id = $this->insertRecordHealthPay($output, $trx_id);
        $this->insertChargesHealthPay($output, $inserted_id);
        $this->insertDevice($output, $inserted_id);
        $this->removeTempData($output);
        if ($this->requestIsApiUser()) {
            // logout user
            $api_user_login_guard = $this->output['api_login_guard'] ?? null;
            if ($api_user_login_guard != null) {
                auth()->guard($api_user_login_guard)->logout();
            }
        }
        try {
            if ($basic_setting->email_notification == true) {
                try{
                $user->notify(new ApprovedMail($user, $output, $trx_id));
                }catch(\Exception $e){
\Log::info('error mail : ,'. $e->getMessage());
}
            }
        } catch (Exception $e) {
        }
    }

    public function insertRecordHealthPay($output, $trx_id) {
        $trx_id = $trx_id;
        $token  = $this->output['tempData']['identifier'] ?? "";
        DB::beginTransaction();
        try{
            $id = DB::table("transactions")->insertGetId([
                'user_id'                     => auth()->user()->id,
                'user_wallet_id'              => $output['wallet']->id,
                'payment_gateway_currency_id' => $output['gateway_currency']->id,
                'type'                        => $output['type'],
                'trx_id'                      => $trx_id,
                'sender_request_amount'       => $output['amount']->requested_amount,
                'sender_currency_code'        => $output['amount']->sender_currency,
                'total_payable'               => $output['amount']->total_payable_amount,
                'exchange_rate'               => $output['amount']->exchange_rate,
                'available_balance'           => $output['wallet']->balance + $output['amount']->requested_amount,
                'remark'                      => ucwords(remove_speacial_char($output['type']," ")) . " With " . $output['gateway']->name,
                'details'                     => json_encode($output['capture']??[]),
                'status'                      => true,
                'attribute'                   => PaymentGatewayConst::SEND,
                'created_at'                  => now(),
            ]);

            $this->updateWalletBalance($output);
            DB::commit();
        }catch(Exception $e) {
            dd($e);
            DB::rollBack();
            throw new Exception($e->getMessage());
        }
        return $id;
    }

    public function insertChargesHealthPay($output,$id) {
        if(Auth::guard(get_auth_guard())->check()){
            $user = auth()->guard(get_auth_guard())->user();
        }
        DB::beginTransaction();
        try{
            DB::table('transaction_details')->insert([
                'transaction_id' => $id,
                'percent_charge' => $output['amount']->gateway_percent_charge,
                'fixed_charge'   => $output['amount']->gateway_fixed_charge,
                'total_charge'   => $output['amount']->gateway_total_charge,
                'created_at'     => now(),
            ]);
            DB::commit();

              // notification
            $notification_content = [
                'title'   => "Add Money",
                'message' => "Your Wallet (".$output['wallet']->currency->code.") balance  has been added ".$output['amount']->requested_amount.' '. $output['wallet']->currency->code,
                'time'    => Carbon::now()->diffForHumans(),
                'image'   => files_asset_path('profile-default'),
            ];

            UserNotification::create([
                'type'    => NotificationConst::BALANCE_ADDED,
                'user_id' => auth()->user()->id,
                'message' => $notification_content,
            ]);
            //Push Notifications
            $basic_setting = BasicSettings::first();
            try {
                if( $basic_setting->push_notification == true){
                    event(new UserNotificationEvent($notification_content,$user));
                    send_push_notification(["user-".$user->id],[
                        'title'     => $notification_content['title'],
                        'body'      => $notification_content['message'],
                        'icon'      => $notification_content['image'],
                    ]);
                }
            } catch (\Throwable $th) {
                //throw $th;
            }
            //admin create notifications
             $notification_content['title'] = 'Add Money '.$output['amount']->requested_amount.' '.$output['wallet']->currency->code.' By '. $output['gateway_currency']->name.' ('.$user->username.')';
            AdminNotification::create([
                'type'      => NotificationConst::BALANCE_ADDED,
                'admin_id'  => 1,
                'message'   => $notification_content,
            ]);
        }catch(Exception $e) {
            DB::rollBack();
            dd($e);
            throw new Exception($e->getMessage());
        }
    } 
    
}
