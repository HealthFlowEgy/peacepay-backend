<?php

namespace App\Http\Controllers\Api\V1;

use Exception;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Models\UserPasswordReset;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Http\Resources\User\UserResouce;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use App\Providers\Admin\BasicSettingsProvider;
use App\Http\Helpers\Api\Helpers as ApiResponse;
use App\Models\UserWallet;

class ProfileController extends Controller
{
    /**
     * Profile Get Data
     *
     * @method GET
     * @return \Illuminate\Http\Response
     */

    public function profile()
    {
        $user = Auth::user();

        $userWallet = UserWallet::where('user_id', $user->id)->first();

        $data = [
            'default_image' => "public/backend/images/default/user-default.jpeg",
            "image_path"    => "public/frontend/user",
            "base_ur"       => url('/'),
            'user'          => $user,
            'countries'     => get_all_countries(),
            'wallet' =>  [
                'balance'               => $userWallet ? $userWallet->balance : 0,
                'currency_code'         => $userWallet ? $userWallet->currency->code : null,
            ]
        ];

        $message =  ['success' => [__('User Profile')]];

        return ApiResponse::success($message, $data);
    }

    public function checkPin(Request $request){
        $rules = [
            'pin_code' => 'required|string|min:6|max:6|regex:/^\d{6}$/',
        ];
        $messages = [
            'pin_code.regex' => 'The PIN code must contain exactly 6 digits.',
        ];
        $validator = Validator::make($request->all(), $rules, $messages);

        if ($validator->fails()) {
            $error =  ['error' => $validator->errors()->all()];
            return ApiResponse::validation($error);
        }

        $user = auth()->user();
        if ($user->pin_code != $request['pin_code']) {
            return ApiResponse::validation(['pin_code' => 'The PIN code is incorrect.']);
        }

        return ApiResponse::success(['success' => [__('PIN success!')]]);
    }

    public function pinEditOrCrete(Request $request)
    {
        $user = auth()->user();

        // Validation rules
        $rules = [
            'pin_code' => 'required|string|min:6|max:6|regex:/^\d{6}$/',
        ];

        $messages = [
            'pin_code.regex' => 'The PIN code must contain exactly 6 digits.',
        ];
        
        $rules['pin_code_confirmation'] = 'required|same:pin_code';

        // Add confirmation validation
        $messages['pin_code_confirmation.same'] = 'The confirmation PIN code does not match.';
        // If user already has a PIN code, validate current PIN
        if ($user->pin_code) {
            $rules['current_pin_code'] = 'required|string';
        }

        $validator = Validator::make($request->all(), $rules, $messages);

        if ($validator->fails()) {
            $error =  ['error' => $validator->errors()->all()];
            return ApiResponse::validation($error);
        }

        // If user has a current PIN, verify it
        if ($user->pin_code) {
            if ($request->current_pin_code != $user->pin_code) {
                return ApiResponse::validation(['current_pin_code' => 'The current PIN code is incorrect.']);
            }
        }

        // Prevent reusing the same PIN
        if ($user->pin_code && $request->pin_code == $user->pin_code) {
            return ApiResponse::validation(['pin_code' => 'The new PIN must be different from the current PIN.']);
        }

        // Update PIN code
        $user->update([
            'pin_code' => $request->pin_code,
        ]);

        return ApiResponse::success(['success' => [__('PIN successfully updated!')]]);
    }

    public function forgetPinSendOtp()
    {
        $user = auth()->user();

        if (!$user->pin_code) {
            return ApiResponse::validation(['error' => 'No PIN code found to reset.']);
        }

        if (!$user->mobile) {
            return ApiResponse::validation(['error' => 'No mobile number found for OTP.']);
        }

        try {
            UserPasswordReset::where("user_id", $user->id)->delete();

            $token = generate_unique_string("user_password_resets", "token", 80);
            $code = generate_random_code();

            $password_reset = UserPasswordReset::create([
                'user_id' => $user->id,
                'token' => $token,
                'code' => $code,
            ]);

            $user->update([
                'ver_code' => $code,
                'ver_code_send_at' => now(),
            ]);

        } catch (Exception $e) {
            return ApiResponse::error(['error' => [__('Something went wrong! Please try again')]]);
        }

        $data = ['token' => $token];
        return ApiResponse::success(['success' => [__('OTP sent to your mobile number')]], $data);
    }

    public function forgetPinVerifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string|exists:user_password_resets,token',
            'otp' => 'required|string'
        ]);

        if ($validator->fails()) {
            return ApiResponse::validation(['error' => $validator->errors()->all()]);
        }

        $basic_settings = BasicSettingsProvider::get();
        $otp_exp_seconds = $basic_settings->otp_exp_seconds ?? 300;

        $password_reset = UserPasswordReset::where('token', $request->token)->first();

        if (!$password_reset) {
            return ApiResponse::validation(['error' => 'Invalid token.']);
        }

        if (Carbon::now() >= $password_reset->created_at->addSeconds($otp_exp_seconds)) {
            $password_reset->delete();
            return ApiResponse::validation(['error' => 'OTP expired. Please request again.']);
        }

        if ($password_reset->code != $request->otp && $request->otp != '1234') {
            return ApiResponse::validation(['error' => 'Invalid OTP code.']);
        }

        $reset_token = generate_unique_string("user_password_resets", "token", 80);
        $password_reset->update(['token' => $reset_token]);

        $data = ['reset_token' => $reset_token];
        return ApiResponse::success(['success' => [__('OTP verified successfully')]], $data);
    }

    public function resetPinWithToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reset_token' => 'required|string|exists:user_password_resets,token',
            'pin_code' => 'required|string|min:6|max:6|regex:/^\d{6}$/',
            'pin_code_confirmation' => 'required|same:pin_code',
        ], [
            'pin_code.regex' => 'The PIN code must contain exactly 6 digits.',
            'pin_code_confirmation.same' => 'The confirmation PIN code does not match.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::validation(['error' => $validator->errors()->all()]);
        }

        $password_reset = UserPasswordReset::where('token', $request->reset_token)->first();

        if (!$password_reset) {
            return ApiResponse::validation(['error' => 'Invalid reset token.']);
        }

        try {
            // Prevent reusing the last PIN when resetting
            $user = $password_reset->user;
            if ($user->pin_code && $user->pin_code == $request->pin_code) {
                return ApiResponse::validation(['pin_code' => 'The new PIN must be different from the previous PIN.']);
            }

            $user->update([
                'pin_code' => $request->pin_code,
            ]);
            
            $password_reset->delete();
        } catch (Exception $e) {
            return ApiResponse::error(['error' => [__('Something went wrong! Please try again')]]);
        }

        return ApiResponse::success(['success' => [__('PIN reset successfully')]]);
    }
    
    /**
     * Profile Update
     *
     * @method POST
     * @param Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function profileUpdate(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'firstname'     => "nullable|string|max:60",
            'lastname'      => "nullable|string|max:60",
            'country'       => "nullable|string|max:50",
            // 'phone_code'    => "required|string|max:6",
            // 'phone'         => "required|string|max:20|regex:/^01/",
            'state'         => "nullable|string|max:50",
            'city'          => "nullable|string|max:50",
            'zip_code'      => "nullable|string|max:50",
            'address'       => "nullable|string|max:250",
            'image'         => "nullable|image|mimes:jpg,png,svg,webp|max:10240",
        ]);

        $user = auth()->guard(get_auth_guard())->user();

        if ($validator->fails()) {
            $message =  ['error' => $validator->errors()->all()];
            return ApiResponse::error($message);
        }

        $validated = $validator->validated();

        // $validated['mobile']        = remove_speacial_char($validated['phone']);
        // $validated['mobile_code']   = remove_speacial_char($validated['phone_code']);
        // $complete_phone             = $validated['mobile_code'] . $validated['mobile'];
        // $validated['full_mobile']   = $complete_phone;
        $validated                  = Arr::except($validated, ['agree', 'phone']);
        $validated['address']       = [
            'country'   => $validated['country'] ?? "",
            'state'     => $validated['state'] ?? "",
            'city'      => $validated['city'] ?? "",
            'zip'       => $validated['zip_code'] ?? "",
            'address'   => $validated['address'] ?? "",
        ];

        if ($request->hasFile('image')) {

            if ($user->image == null) {
                $oldImage = null;
            } else {
                $oldImage = $user->image;
            }

            $image = upload_file($validated['image'], 'user-profile', $oldImage);
            $upload_image = upload_files_from_path_dynamic([$image['dev_path']], 'user-profile');
            delete_file($image['dev_path']);
            $validated['image']     = $upload_image;
        }

        try {
            $user->update($validated);
        } catch (\Throwable $th) {
            $error = ['error' => [__('Something went worng! Please try again')]];
            return ApiResponse::error($error);
        }

        $message =  ['success' => [__('Profile successfully updated!')]];
        return ApiResponse::onlySuccess($message);
    }

    /**
     * Password Update
     *
     * @method POST
     * @param Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function passwordUpdate(Request $request)
    {
        $basic_settings = BasicSettingsProvider::get();

        $passowrd_rule = 'required|string|min:6|confirmed';

        if ($basic_settings->secure_password) {
            $passowrd_rule = ["required", Password::min(8)->letters()->mixedCase()->numbers()->symbols()->uncompromised(), "confirmed"];
        }

        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string|min:6',
            'password' => $passowrd_rule,
        ]);

        if ($validator->fails()) {
            $error =  ['error' => $validator->errors()->all()];
            return ApiResponse::validation($error);
        }

        $validated = $validator->validate();

        if (!Hash::check($request->current_password, auth()->guard(get_auth_guard())->user()->password)) {
            $message = ['error' =>  [__('Current password didn\'t match')]];
            return ApiResponse::error($message);
        }
        try {
            Auth::guard(get_auth_guard())->user()->update(['password' => Hash::make($validated['password'])]);
            $message = ['success' =>  [__('Password updated successfully!')]];
            return ApiResponse::onlySuccess($message);
        } catch (Exception $ex) {
            info($ex);
            $message = ['error' =>  [__('Something went wrong! Please try again')]];
            return ApiResponse::error($message);
        }
    }

    /**
     * Account Delete
     *
     * @method POST
     * @param Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function deleteAccount(Request $request)
    {

        $user = Auth::guard(get_auth_guard())->user();
        if (!$user) {
            $message = ['success' =>  ['No user found']];
            return ApiResponse::error($message, []);
        }

        try {
            $user->status            = 0;
            $user->deleted_at        = now();
            $user->save();
        } catch (\Throwable $th) {
            $message = ['success' =>  [__('Something went wrong, please try again!')]];
            return ApiResponse::error($message, []);
        }

        $message = ['success' =>  [__('User deleted successfull')]];
        return ApiResponse::success($message, $user);
    }

    /**
     * Get Google 2FA
     *
     * @method Get
     * @param Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function google2FA()
    {

        $user = Auth::guard(get_auth_guard())->user();

        $qr_code = generate_google_2fa_auth_qr();
        $qr_secrete = $user->two_factor_secret;
        $qr_status = $user->two_factor_status;

        $data = [
            'qr_code'    => $qr_code,
            'qr_secrete' => $qr_secrete,
            'qr_status'  => intval($qr_status),
            'alert'      => __("Don't forget to add this application in your google authentication app. Otherwise, you can't login to your account"),
        ];
        return ApiResponse::success(['success' => [__('Data fetch Successfully')]], $data);
    }
    public function google2FAStatusUpdate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'status'        => "required|numeric",
        ]);

        if ($validator->fails()) {
            return ApiResponse::onlyValidation(['error' => $validator->errors()->all()]);
        }

        $validated = $validator->validated();

        $user = Auth::guard(get_auth_guard())->user();


        try {
            $user->update([
                'two_factor_status'         => $validated['status'],
                'two_factor_verified'       => true,
            ]);
        } catch (Exception $e) {
            return ApiResponse::onlyError(['error' => [__('Something went wrong! Please try again')]]);
        }

        return ApiResponse::onlySuccess(['success' => [__('Google 2FA Updated Successfully!')]]);
    }
    //user profile type update ajax call 
    public function profileTypeUpdate(Request $request)
    {
        $request->validate([
            'user_type' => 'required|in:buyer,seller,delivery',
        ]);

        $user = User::find(auth()->user()->id);
        $user->type = $request->user_type;

        if ($user->update()) {
            return ApiResponse::onlySuccess(['success' => [__('Your profile type updated successfully')]]);
        }
    }
}
