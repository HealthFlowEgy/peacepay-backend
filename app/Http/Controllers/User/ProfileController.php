<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Admin\SetupKyc;
use App\Models\User;
use App\Providers\Admin\BasicSettingsProvider;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Arr;

class ProfileController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $page_title = __("User Profile");
        $kyc_data = SetupKyc::userKyc()->first();
        return view('user.sections.profile.index', compact("page_title", "kyc_data"));
    }
    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request)
    {
        $validated = Validator::make($request->all(), [
            'firstname'     => "required|string|max:60",
            'lastname'      => "required|string|max:60",
            // 'country'       => "required|string|max:50",
            // 'phone_code'    => "required|string|max:20",
            // 'phone'         => "required|string|max:20",
            'state'         => "nullable|string|max:50",
            'city'          => "nullable|string|max:50",
            'zip_code'      => "nullable|string",
            'address'       => "nullable|string|max:250",
            'image'         => "nullable|image|mimes:jpg,png,svg,webp|max:10240",
        ])->validate();

        // $validated['mobile']        = remove_speacial_char($validated['phone']);
        // $validated['mobile_code']   = remove_speacial_char($validated['phone_code']);

        // $complete_phone             = $validated['mobile_code'] . $validated['mobile'];
        // $validated['full_mobile']   = $complete_phone;
        $validated                  = Arr::except($validated, ['agree', 'phone_code', 'phone']);
        $validated['address']       = [
            'country'   => $validated['country'] ?? "Egypt",
            'state'     => $validated['state'] ?? "",
            'city'      => $validated['city'] ?? "",
            'zip'       => $validated['zip_code'] ?? "",
            'address'   => $validated['address'] ?? "",
        ];

        if ($request->hasFile("image")) {
            $image = upload_file($validated['image'], 'user-profile', auth()->user()->image);
            $upload_image = upload_files_from_path_dynamic([$image['dev_path']], 'user-profile');
            delete_file($image['dev_path']);
            $validated['image']     = $upload_image;
        }

        try {
            auth()->user()->update($validated);
        } catch (Exception $e) {
            return back()->with(['error' => [__('Something went worng! Please try again')]]);
        }

        return back()->with(['success' => [__('Profile successfully updated!')]]);
    }
    public function passwordUpdate(Request $request)
    {
        $basic_settings = BasicSettingsProvider::get();
        $passowrd_rule = "required|string|min:6|confirmed";
        if ($basic_settings->secure_password) {
            $passowrd_rule = ["required", Password::min(8)->letters()->mixedCase()->numbers()->symbols()->uncompromised(), "confirmed"];
        }
        $request->validate([
            'current_password'      => "required|string",
            'password'              => $passowrd_rule,
        ]);
        if (!Hash::check($request->current_password, auth()->user()->password)) {
            throw ValidationException::withMessages([
                'current_password'      => 'Current password didn\'t match',
            ]);
        }
        try {
            auth()->user()->update([
                'password'  => Hash::make($request->password),
            ]);
        } catch (Exception $e) {
            return back()->with(['error' => [__('Something went wrong! Please try again')]]);
        }
        return back()->with(['success' => [__('Password successfully updated!')]]);
    }
    //user profile type update ajax call 
    public function profileTypeUpdate(Request $request)
    {        
        $user = User::find(auth()->user()->id);
        $user->type = $request->user_type;

        if ($user->update()) {
            return response()->json(['success' => __('Your profile type updated successfully')]);
        }
    }

    public function pin()
    {
        $page_title = __("PIN Code");
        return view('user.sections.profile.pin', compact("page_title"));
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

        // If user already has a PIN code, validate current PIN
        if ($user->pin_code) {
            $rules['current_pin_code'] = 'required|string';
            $rules['pin_code_confirmation'] = 'required|same:pin_code';

            // Add confirmation validation
            $messages['pin_code_confirmation.same'] = 'The confirmation PIN code does not match.';
        }

        $validator = Validator::make($request->all(), $rules, $messages);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        // If user has a current PIN, verify it
        if ($user->pin_code) {
            if ($request->current_pin_code != $user->pin_code) {
                return back()->withErrors(['current_pin_code' => 'The current PIN code is incorrect.'])->withInput();
            }
        }

        // Update PIN code
        $user->update([
            'pin_code' => $request->pin_code,
        ]);

        return redirect()->route('user.dashboard')->with(['success' => [__('PIN successfully updated!')]]);
    }
}
