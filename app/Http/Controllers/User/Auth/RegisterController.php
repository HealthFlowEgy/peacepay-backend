<?php

namespace App\Http\Controllers\User\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin\Currency;
use App\Providers\Admin\BasicSettingsProvider;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Auth\Events\Registered;
use App\Models\User;
use App\Traits\User\RegisteredUsers;
use Exception;
use Illuminate\Support\Facades\Hash;

class RegisterController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Register Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the registration of new users as well as their
    | validation and creation. By default this controller uses a trait to
    | provide this functionality without requiring any additional code.
    |
    */

    use RegistersUsers, RegisteredUsers;

    protected $basic_settings;

    public function __construct()
    {
        $this->basic_settings = BasicSettingsProvider::get();
    }

    /**
     * Show the application registration form.
     *
     * @return \Illuminate\View\View
     */
    public function showRegistrationForm()
    {
        if ($agree_policy = $this->basic_settings->user_registration == 0) {
            return back()->with(['error' => ["User registration is now off"]]);
        }
        $client_ip = request()->ip() ?? false;
        $user_country = geoip()->getLocation($client_ip)['country'] ?? "";

        $page_title = setPageTitle("User Registration");
        return view('user.auth.register', compact(
            'page_title',
            'user_country',
        ));
    }

    /**
     * Handle a registration request for the application.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        $validated = $this->validator($request->all())->validate();

        $existsUser = User::where([
            'mobile' => $validated['mobile'],
            'registered' => 1,
        ])->first();
        if ($existsUser) {
            return redirect()->back()->with([
                'error' => ['Mobile number already registered.'],
            ]);
        }

        $basic_settings             = $this->basic_settings;

        $validated = Arr::except($validated, ['agree']);
        $validated['email_verified']    = ($basic_settings->email_verification == true) ? false : true;
        $validated['sms_verified']      = ($basic_settings->sms_verification == true) ? false : true;
        $validated['kyc_verified']      = ($basic_settings->kyc_verification == true) ? false : true;
        $validated['password']          = Hash::make($validated['password']);
        $validated['username']          = make_username($validated['firstname'], $validated['lastname']);
        $validated['mobile_code']       = env('MOBILE_CODE');
        $validated['full_mobile']       = env('MOBILE_CODE') . $validated['mobile'];
        $validated['email']             = env('MOBILE_CODE') . $validated['mobile'] . '_' . time() . '@example.com';
        $validated['user_exists']       = User::where('mobile', $validated['mobile'])->first() ? true : false;
        $validated['address']       = [
            'country'   => 'Saudi Arabia'
        ];

        event(new Registered($user = $this->create($validated)));
        $this->guard()->login($user);

        return $this->registered($request, $user);
    }


    /**
     * Get a validator for an incoming registration request.
     *
     * @param  array  $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    public function validator(array $data)
    {

        $basic_settings = $this->basic_settings;
        $passowrd_rule = "required|string|min:6";
        if ($basic_settings->secure_password) {
            $passowrd_rule = ["required", Password::min(8)->letters()->mixedCase()->numbers()->symbols()->uncompromised()];
        }
        if ($basic_settings->agree_policy) {
            $agree = 'required|in:on';
        } else {
            $agree = 'nullable';
        }
        $messages = [
            'mobile.regex' => 'The mobile number must start with 0.',
        ];
        return Validator::make($data, [
            'firstname'     => 'required|string|max:60',
            'lastname'      => 'required|string|max:60',
            'type'          => 'required|string|max:60',
            'mobile'        => 'required|string|max:11|min:11|regex:/^0/',
            'password'      => $passowrd_rule,
            'agree'         => $agree,
        ], $messages);
    }


    /**
     * Create a new user instance after a valid registration.
     *
     * @param  array  $data
     * @return \App\Models\User
     */
    protected function create(array $data)
    {
        if ($data['user_exists']) {
            $user = User::where('mobile', $data['mobile'])->first();
            $user->firstname = $data['firstname'];
            $user->lastname = $data['lastname'];
            $user->type = $data['type'];
            $user->registered = 1;
            $user->save();
            return $user;
        }
        return User::create($data);
    }


    /**
     * The user has been registered.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $user
     * @return mixed
     */
    protected function registered(Request $request, $user)
    {
        $this->createUserWallets($user);
        return redirect()->intended(route('user.dashboard'));
    }
}
