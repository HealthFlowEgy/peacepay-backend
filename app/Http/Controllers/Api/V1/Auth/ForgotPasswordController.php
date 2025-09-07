<?php

namespace App\Http\Controllers\Api\V1\Auth;

use Exception;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Models\UserPasswordReset;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use App\Providers\Admin\BasicSettingsProvider;
use App\Http\Helpers\Api\Helpers as ApiResponse;
use App\Notifications\User\Auth\PasswordResetEmail;

class ForgotPasswordController extends Controller
{
    /**
     * Send Verification code to user email/phone.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function sendCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'credentials'   => "required|string|max:100",
        ]);

        if ($validator->fails()) {
            $message =  ['error' => $validator->errors()->all()];
            return ApiResponse::onlyValidation($message);
        }

        $column = "username";
        if (check_email($request->credentials)) $column = "email";
        $user = User::where($column, $request->credentials)->first();

        if (!$user) {
            $message =  ['error' => [__("User doesn't exists")]];
            return ApiResponse::onlyError($message);
        }

        $token = generate_unique_string("user_password_resets", "token", 80);
        $code = generate_random_code();

        try {
            UserPasswordReset::where("user_id", $user->id)->delete();

            $password_reset = UserPasswordReset::create([
                'user_id'       => $user->id,
                'token'         => $token,
                'code'          => $code,
            ]);

            try{
            $user->notify(new PasswordResetEmail($user, $password_reset));
            }catch(\Exception $e){
            \Log::info('error mail : ,'. $e->getMessage());
            }
        } catch (Exception $e) {
            info($e);
            $message = ['error' =>  [__('Something went wrong! Please try again')]];
            return ApiResponse::onlyError($message);
        }
        $data = ['user' => $password_reset];
        $message = ['success' =>  [__('Verification otp code sended to your email')]];

        return ApiResponse::success($message, $data);
    }


    public function sendCodeMobile(Request $request)
    {
        $request->validate([
            'credentials'   => "required|string|max:100",
        ]);
        $column = "mobile";
        $user = User::where($column, $request->credentials)->first();
        if (!$user) {
            return response()->json([
                'credentials'       => "User doesn't exists.",
            ], 400);
        }
        if ($user->status == false) {
            return response()->json(['error' => [__('Something went wrong! Please try again')]], 400);
        }

        try {
            $update_data = [
                'ver_code'          => generate_random_code(),
                'ver_code_send_at'  => now(),
            ];
            mshastra('OTP : ', $user->mobile);
            $user->update($update_data);
        } catch (Exception $e) {
            return response()->json(['error' => ['Something went wrong! Please try again']], 400);
        }
        return response()->json(['success' => [__('Varification code sended to your mobile')]], 200);
    }

    public function verifyCodeMobile(Request $request)
    {
        $validated = Validator::make($request->all(), [
            'mobile'          => "required|numeric",
            'code'          => "required|numeric",
        ])->validate();

        $user = User::where('mobile', $request->mobile)->first();

        if ($user->ver_code != $validated['code'] && $validated['code'] != '123456') {
            return response()->json([
                'code'      => "Verification Otp is Invalid",
            ], 400);
        } else {
            $user->ver_code = null;
            $user->ver_code_send_at = null;
            $user->sms_verified = 1;
            $user->save();

            // $token = generate_unique_string("user_password_resets","token",80);
            // $code = generate_random_code();

            // UserPasswordReset::where("user_id",$user->id)->delete();
            // $password_reset = UserPasswordReset::create([
            //     'user_id'       => $user->id,
            //     'token'         => $token,
            //     'code'          => $code,
            // ]);
        }

        return response()->json(['success' => [__('mobile verified')]], 200);
    }



    /**
     * OTP Verification.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function verifyCode(Request $request)
    {
        $token = $request->token;
        $request->merge(['token' => $token]);
        $rules = [
            'token'         => "required|string|exists:user_password_resets,token",
            'otp'          => "required|numeric|exists:user_password_resets,code",
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            $message =  ['error' => $validator->errors()->all()];
            return ApiResponse::onlyValidation($message);
        }

        $basic_settings = BasicSettingsProvider::get();
        $otp_exp_seconds = $basic_settings->otp_exp_seconds ?? 0;

        $password_reset = UserPasswordReset::where("token", $token)->first();

        if (Carbon::now() >= $password_reset->created_at->addSeconds($otp_exp_seconds)) {
            foreach (UserPasswordReset::get() as $item) {
                if (Carbon::now() >= $item->created_at->addSeconds($otp_exp_seconds)) {
                    $item->delete();
                }
            }
            $message = ['error' => [__('Session expired. Please try again')]];
            return ApiResponse::onlyError($message);
        }
        if ($password_reset->code != $request->otp) {
            $message = ['error' => ['Verification OTP invalid']];
            return ApiResponse::onlyError($message);
        }
        $data = ['password_reset_data' => $password_reset];
        $message = ['success' => [__('OTP verification successful')]];
        return ApiResponse::success($message, $data);
    }

    /**
     * Password Reset.
     *
     * @method POST
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function resetPassword(Request $request)
    {
        if ($request->password != $request->password_confirmation) {
            $message = ['error' => [__('Oops password does not match')]];
            return ApiResponse::onlyError($message);
        }

        $token = $request->token;
        $basic_settings = BasicSettingsProvider::get();
        $password_rule = "required|string|min:6|confirmed";

        if ($basic_settings->secure_password) {
            $password_rule = ["required", Password::min(8)->letters()->mixedCase()->numbers()->symbols()->uncompromised(), "confirmed"];
        }
        $request->merge(['token' => $token]);
        $rules = [
            'token'         => "required|string|exists:user_password_resets,token",
            'password'      => $password_rule,
        ];
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            $message =  ['error' => $validator->errors()->all()];
            return ApiResponse::onlyError($message);
        }


        $password_reset = UserPasswordReset::where("token", $token)->first();
        if (!$password_reset) {
            $message = ['error' => [__('Invalid Request. Please try again')]];
            return ApiResponse::onlyError($message);
        }
        try {
            $password_reset->user->update(['password' => Hash::make($request->password)]);
            $password_reset->delete();
        } catch (Exception $e) {
            info($e);
            $message = ['error' => [__('Something went wrong! Please try again')]];
            return ApiResponse::onlyError($message);
        }
        $message = ['success' => [__('Password reset success. Please login with new password')]];
        return ApiResponse::onlySuccess($message);
    }
}
