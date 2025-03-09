<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Admin\PaymentGateway;
use Illuminate\Http\Request;
use Softonic\GraphQL\ClientBuilder;

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
        $credentials = $this->getCredentials($output);

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

        session()->put('userTokenHealthPay', $response->getData()['authUser']['userToken']);
        
        $topupWalletUser = $this->topupWalletUser($clientUser);

        return redirect()->to($topupWalletUser['iframeUrl']);
    }


    
}
