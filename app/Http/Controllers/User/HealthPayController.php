<?php

namespace App\Http\Controllers\User;

use App\Constants\EscrowConstants;
use App\Constants\NotificationConst;
use App\Http\Controllers\Controller;
use App\Models\Admin\BasicSettings;
use App\Models\Admin\Currency;
use App\Models\Admin\PaymentGateway;
use App\Models\Escrow;
use App\Models\UserNotification;
use App\Models\UserWallet;
use App\Notifications\Escrow\EscrowApprovel;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Softonic\GraphQL\ClientBuilder;
use App\Events\User\NotificationEvent as UserNotificationEvent;

class HealthPayController extends Controller
{
    use \App\Traits\PaymentGateway\HealthPay;

    public function showConfirmMobile()
    {
        return view('user.auth.verify-mobile-healthpay');
    }


    public function verifyMobile(Request $request)
    {
        $request->validate([
            'otp' => 'required'
        ]);

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


        $mobileHealthPay = session()->get('mobileHealthPay');
        $output['gateway'] = PaymentGateway::where('alias','healthpay')->first();
        $credentials = $this->getCredentialsHealthPay($output);

        $clientUser = ClientBuilder::build($credentials['baseURL'], [
            'headers' => [
                'api-header' => $credentials['apiHeader'],
                'Authorization' => 'Bearer ' . session()->get('merchantTokenHealthPay'),
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
        

        $userHealthpayWallet = getUserHealthpayWallet($userToken,$output['gateway']);

        $deductAmount = (float) session()->get('topupAmountHealthPay');
        if($deductAmount > (float) $userHealthpayWallet['total']) {
            $amount = $deductAmount - (float) $userHealthpayWallet['total'];
            $topupWalletUser = $this->topupWalletUser($clientUser,$amount);
            return redirect()->to($topupWalletUser['iframeUrl']);
        }else{
            $id = session()->get('escrowId');
            $escrow    = Escrow::findOrFail($id);
            
            $this->escrowWalletPayment($escrow);
            $escrow->payment_type = EscrowConstants::GATEWAY;
            $escrow->status       = EscrowConstants::ONGOING;

            deductFromUser($userToken, $deductAmount , 'deduct ' . $deductAmount,$output['gateway']);

            $escrow->save();

            return redirect()->route('user.my-escrow.index', [
                'id' => $escrow->id,
            ])->with([
                'success' => ['Payment successful'],
            ]);
        }
        
        
    }


    public function escrowWalletPayment($escrowData) {
        $sender_currency = Currency::where('code', $escrowData->escrow_currency)->first();
        $user_wallet     = UserWallet::where(['user_id' => auth()->user()->id, 'currency_id' => $sender_currency->id])->first(); 
        //buyer amount calculation
        $amount = $escrowData->amount;
        if ($escrowData->role == EscrowConstants::SELLER_TYPE && $escrowData->who_will_pay == EscrowConstants::ME) {
            $buyer_amount = $amount;
        }else if($escrowData->role == EscrowConstants::SELLER_TYPE && $escrowData->who_will_pay == EscrowConstants::BUYER){
            $buyer_amount = $amount + $escrowData->escrowDetails->fee;
        }else if($escrowData->role == EscrowConstants::SELLER_TYPE && $escrowData->who_will_pay == EscrowConstants::HALF){
            $buyer_amount = $amount + ($escrowData->escrowDetails->fee/2);
        }
        $user_wallet->balance -= $buyer_amount;
        $escrowData->escrowDetails->buyer_pay = $buyer_amount;
        
        DB::beginTransaction();
        try{ 
            // $user_wallet->save();
            $escrowData->escrowDetails->save();
            DB::commit();
            $this->approvelNotificationSend($escrowData->user, $escrowData);
        }catch(Exception $e) {
            DB::rollBack();
            throw new Exception($e->getMessage());
        } 
    }

    public function approvelNotificationSend($user, $escrow){
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
        try{
            try{
            $user->notify(new EscrowApprovel($user,$escrow));
            }catch(\Exception $e){
\Log::info('error mail : ,'. $e->getMessage());
}
            if( $basic_setting->push_notification == true){
                event(new UserNotificationEvent($notification_content,$user));
                send_push_notification(["user-".$user->id],[
                    'title'     => $notification_content['title'],
                    'body'      => $notification_content['message'],
                    'icon'      => $notification_content['image'],
                ]);
            }
        }catch(Exception $e){
          
        }
     
    }
    
}
