<?php

namespace App\Http\Controllers\User\Auth;

use App\Constants\GlobalConst;
use App\Http\Controllers\Controller;
use App\Models\Admin\Currency;
use App\Models\UserDevice;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use DB;
use App\Traits\User\LoggedInUsers;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Validator;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    protected $request_data;

    use AuthenticatesUsers, LoggedInUsers;

    public function showLoginForm() {
        $page_title = setPageTitle("User Login");
        return view('user.auth.login',compact(
            'page_title',
        ));
    }


    /**
     * Validate the user login request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function validateLogin(Request $request)
    {
        $this->request_data = $request;
        $request->validate([
            'credentials'   => 'required|string',
            'password'      => 'required|string',
        ]);
    }


    /**
     * Get the needed authorization credentials from the request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    protected function credentials(Request $request)
    {
        // $request->merge(['status' => true]);
        $request->merge([$this->username() => $request->credentials]);
        return $request->only($this->username(), 'password','status');
    }


    /**
     * Get the login username to be used by the controller.
     *
     * @return string
     */
    public function username()
    {
        return 'mobile';
        // $request = $this->request_data->all();
        // $credentials = $request['credentials'];
        // if(filter_var($credentials,FILTER_VALIDATE_EMAIL)) {
        //     return "email";
        // }
        // return "username";
    }

    /**
     * Get the failed login response instance.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function sendFailedLoginResponse(Request $request)
    {
        throw ValidationException::withMessages([
            "credentials" => [trans('auth.failed')],
        ]);
    }


    /**
     * Get the guard to be used during authentication.
     *
     * @return \Illuminate\Contracts\Auth\StatefulGuard
     */
    protected function guard()
    {
        return Auth::guard("web");
    }


    /**
     * The user has been authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $user
     * @return mixed
     */
    protected function authenticated(Request $request, $user)
    {
        $user->update([
            'two_factor_verified'   => false,
        ]);
        $this->refreshUserWallets($user);
        $this->createLoginLog($user);
        return redirect()->intended(route('user.dashboard'));
    }

    
    public function showVerifyForm() {
        $page_title = setPageTitle("Verify User");
        
        $user = auth()->user();

        if(!$user) return redirect()->route('user.password.forgot')->with(['error' => [__('Password Reset Token Expired')]]);
        
        $resend_time = 0;
        if(!$user->ver_code_send_at){
            $user->ver_code_send_at = now();
            $user->ver_code = generate_random_code();
            $user->save();
        }
        
        $verCodeSendAt = Carbon::parse($user->ver_code_send_at);

        if(Carbon::now() <= $verCodeSendAt->addMinutes(GlobalConst::USER_PASS_RESEND_TIME_MINUTE)) {
            $resend_time = Carbon::now()->diffInSeconds($verCodeSendAt->addMinutes(GlobalConst::USER_PASS_RESEND_TIME_MINUTE));
        }
        
        $full_mobile = $user->full_mobile ?? "";
        return view('user.auth.verify-mobile',compact('page_title','full_mobile','resend_time'));
    }


    public function resendCode()
    {
        $user = auth()->user();
        if(!$user) return back()->with(['error' => ['Request token is invalid']]);

        $verCodeSendAt = Carbon::parse($user->ver_code_send_at);

        if(Carbon::now() <= $verCodeSendAt->addMinutes(GlobalConst::USER_PASS_RESEND_TIME_MINUTE)) {
            throw ValidationException::withMessages([
                'code'      => 'You can resend verification code after '.Carbon::now()->diffInSeconds($verCodeSendAt->addMinutes(GlobalConst::USER_PASS_RESEND_TIME_MINUTE)). ' seconds',
            ]);
        }

        DB::beginTransaction();
        try{
            $update_data = [
                'ver_code'          => generate_random_code(),
                'ver_code_send_at'    => now(),
            ];
            $user->update($update_data);
            DB::commit();
        }catch(Exception $e) {
            DB::rollback();
            return back()->with(['error' => [__('Something went worng. please try again')]]);
        }
        return redirect()->route('user.code.verify.mobile')->with(['success' => [__('Varification code resend success!')]]);

    }


    public function verifyCode(Request $request)
    {
        $validated = Validator::make($request->all(),[
            'code'          => "required|numeric",
        ])->validate();

        $user = auth()->user();

        if($user->ver_code != $validated['code'] 
        // && $validated['code'] != '1234'
        ) {
            throw ValidationException::withMessages([
                'code'      => "Verification Otp is Invalid",
            ]);
        }else{
            $user->update([
                'ver_code'          => null,
                'ver_code_send_at'  => null,
                'sms_verified'      => 1
            ]);

            $fingerprint = $this->generateDeviceFingerprint($request);
            $device = UserDevice::where('user_id', $user->id)
            ->where('device_fingerprint', $fingerprint)
            ->first();

            if(!$device){
                UserDevice::create([
                    'user_id' => $user->id,
                    'device_fingerprint' => $fingerprint,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->header('agent') ?? $request->userAgent(),
                    'last_used_at' => Carbon::now(),
                ]);
            }
            
        }

        return redirect()->route('user.dashboard');
    }

    private function generateDeviceFingerprint(Request $request)
    {
        // Combine multiple factors to create a unique device identifier
        $data = [
            'user_agent' => $request->header('agent') ?? $request->userAgent(),
            'ip' => $request->ip(),
            // You can add more factors if available, like:
            // 'accept_language' => $request->header('Accept-Language'),
            // 'screen_resolution' => $request->input('screen_resolution'), // Would need to be sent from frontend
        ];
        
        // Create a hash of the combined data
        return hash('sha256', json_encode($data));
    }
}
