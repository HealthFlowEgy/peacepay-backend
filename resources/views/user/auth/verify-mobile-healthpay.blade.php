@extends('layouts.master')

@push('css')
    
@endpush

@section('content') 
    <!--~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
        Start OTP Verification
    ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~-->
    <div class="account-section ptb-80">
        <div class="account-area">
            <div class="account-form-area">
                <div class="account-logo">
                    <a class="site-logo site-title" href="{{ setRoute('index') }}">
                        <img src="{{ get_logo($basic_settings) }}" data-white_img="{{ get_logo($basic_settings,'white') }}"
                        data-dark_img="{{ get_logo($basic_settings,'dark') }}" alt="logo">
                    </a>
                </div>
                <h3 class="title">{{ __("OTP Verification") }}</h3>
                <p>{{ __("Please check your mobile to get the OTP (One time password).") }}</p>
                <form action="{{ setRoute('user.healthpay.verifyMobile') }}" class="account-form" method="POST">
                    @csrf
                    <div class="row ml-b-20">
                        <div class="col-lg-12 form-group">
                            @include('admin.components.form.input',[
                                'name'          => "otp",
                                'placeholder'   => __("Enter Verification Code"),
                                'required'      => true,
                                'value'         => old("otp"),
                            ])
                        </div>
                        
                        <div class="col-lg-12 form-group text-center">
                            <button type="submit" class="btn--base w-100">{{ __("Verify") }}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!--~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
        End OTP Verification
    ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~-->
@endsection
@push('script')
<script>
    
</script>
@endpush