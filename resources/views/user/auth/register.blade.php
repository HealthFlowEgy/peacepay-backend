@extends('layouts.master')
@php
    $lang = selectedLang();
    $auth_slug = Illuminate\Support\Str::slug(App\Constants\SiteSectionConst::AUTH_SECTION);
    $auth_text = App\Models\Admin\SiteSections::getData( $auth_slug)->first();
@endphp
@section('content')
<style>
    .mobile-error-message {
        color: red;
        font-size: 0.8em;
        margin-top: 5px;
    }
</style>
   <!--~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
    Start Account
    ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~-->
    <div class="account-section">
        <div class="account-area">
            <div class="account-form-area">
                <div class="account-logo">
                    <a class="site-logo site-title" href="{{ setRoute('index') }}">
                        <img src="{{ get_logo($basic_settings) }}" data-white_img="{{ get_logo($basic_settings,'white') }}"
                        data-dark_img="{{ get_logo($basic_settings,'dark') }}" alt="logo">
                    </a>
                </div>
                <h3 class="title">{{ __(@$auth_text->value->language->$lang->register_title) }}</h3>
                <p>{{ __(@$auth_text->value->language->$lang->register_text) }}</p>
                <form action="{{ setRoute('user.register.submit') }}" class="account-form" method="POST">
                    @csrf
                    <div class="row">
                        
                        <div class="col-xl-12 col-lg-12 form-group custom-toggle">
                            @include('admin.components.form.switcher',[
                                'name'          => 'type',
                                'value'         => old('type','buyer'),
                                'options'       => ['Buyer' => 'buyer','Seller' => 'seller'],
                            ])
                        </div>
                        <div class="col-lg-6 col-md-12 form-group">
                            @include('admin.components.form.input',[
                                'name'          => "firstname",
                                'placeholder'   => __("First Name"),
                                'value'         => old("firstname"),
                            ])
                        </div>
                        <div class="col-lg-6 col-md-12 form-group">
                            @include('admin.components.form.input',[
                                'name'          => "lastname",
                                'placeholder'   => __("Last Name"),
                                'value'         => old("lastname"),
                            ])
                        </div>
                        <div class="col-lg-12 form-group">
                            @include('admin.components.form.input',[
                                    'type'          => "text",
                                    'name'          => "mobile",
                                    'placeholder'   => __("Mobile"),
                                    'value'         => old("mobile"),
                                    'class'         => "mobile-input", // Add a class for potential additional styling                            
                                ])
                        </div>
                        <div class="col-lg-12 form-group show_hide_password">
                            <input type="password" class="form--control" name="password" placeholder="{{ __('Password') }}" required>
                            <a href="javascript:void(0)" class="show-pass"><i class="fa fa-eye-slash" aria-hidden="true"></i></a> 
                        </div>
                        <div class="col-lg-12 form-group">
                            <div class="custom-check-group">
                                <input type="checkbox" id="level-1" name="agree">
                                <label for="level-1">{{ __('I have agreed with') }} <a href="{{ route('page.view','terms-and-conditions') }}" target="_blanck">{{ __('Terms Of Use') }}</a> & <a href="{{ route('page.view','privacy-policy') }}" target="_blanck">{{ __('Privacy Policy') }}</a></label>
                            </div> 
                        </div>
                        <div class="col-lg-12 form-group text-center">
                            <button type="submit" class="submit--btn btn--base w-100">{{ __("Register Now") }}</button>
                        </div>
                        <div class="col-lg-12 text-center">
                            <div class="account-item">
                                <label>{{ __("Already Have An Account?") }} <a href="{{ setRoute('user.login') }}">{{ __("Login Now") }}</a></label>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!--~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
        End Account
    ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~-->
@endsection 

@push('script')

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const mobileInputs = document.querySelectorAll('.mobile-input');
        const submitBtns = document.querySelectorAll('.submit--btn');
        
        mobileInputs.forEach(function(input) {
            // Create error message element
            const errorElement = document.createElement('div');
            errorElement.className = 'mobile-error-message';
            errorElement.style.color = 'red';
            errorElement.style.fontSize = '0.8em';
            errorElement.style.marginTop = '5px';
            errorElement.style.display = 'none';
            
            // Insert error message after the input
            input.parentNode.insertBefore(errorElement, input.nextSibling);
    
            // Disable submit buttons initially
            submitBtns.forEach(btn => {
                btn.disabled = true;
                btn.style.opacity = '0.5';
                btn.style.cursor = 'not-allowed';
            });
    
            // Validation function
            function validateMobileNumber() {
                const mobileValue = input.value.trim();
                const isValid = mobileValue.length === 11 && mobileValue.startsWith('0');
                
                submitBtns.forEach(btn => {
                    btn.disabled = !isValid;
                    btn.style.opacity = isValid ? '1' : '0.5';
                    btn.style.cursor = isValid ? 'pointer' : 'not-allowed';
                });
    
                if (mobileValue.length > 0 && !mobileValue.startsWith('0')) {
                    errorElement.textContent = 'Mobile number must start with 0';
                    errorElement.style.display = 'block';
                    // input.value = '0' + mobileValue.replace(/\D/g, '').slice(0, -1);
                } else if (mobileValue.length !== 11) {
                    errorElement.textContent = 'Mobile number must be 11 digits';
                    errorElement.style.display = 'block';
                } else {
                    errorElement.style.display = 'none';
                }
            }
    
            // Prevent non-numeric input and limit to 11 digits
            input.addEventListener('input', function(e) {
                // Remove any non-digit characters
                this.value = this.value.replace(/\D/g, '');
                
                // Limit to 11 digits
                if (this.value.length > 11) {
                    this.value = this.value.slice(0, 11);
                }
    
                validateMobileNumber();
            });
    
            // Final validation on blur
            input.addEventListener('blur', validateMobileNumber);
        });
    });
</script>
    


@endpush