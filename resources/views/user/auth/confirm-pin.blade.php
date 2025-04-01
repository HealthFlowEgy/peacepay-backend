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
                <h3 class="title">{{ __("PIN Code") }}</h3>
                <form action="{{ setRoute('user.pin.code.confirm') }}" class="account-form pin-verification-form" method="POST">
                    @csrf
                    <div class="row ml-b-20">
                        <div class="col-lg-12 form-group">
                            @include('admin.components.form.input',[
                                'name'          => "pin_code",
                                'placeholder'   => __("Enter PIN Code"),
                                'required'      => true,
                                'value'         => old("pin_code"),
                                'class'         => "pin-code-input"
                            ])
                            <small class="pin-code-error text-danger" style="display: none;">{{ __("Please enter a 6-digit PIN code") }}</small>
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
    function resetTime (second = 60) {
        var coundDownSec = second;
        var countDownDate = new Date();
        countDownDate.setMinutes(countDownDate.getMinutes() + 120);
        var x = setInterval(function () {  // Get today's date and time
            var now = new Date().getTime();  // Find the distance between now and the count down date
            var distance = countDownDate - now;  // Time calculations for days, hours, minutes and seconds  var hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            var minutes = Math.floor((distance % (1000 * coundDownSec)) / (1000 * coundDownSec));
            var seconds = Math.floor((distance % (1000 * coundDownSec)) / 1000);  // Output the result in an element with id="time"
            document.getElementById("time").innerHTML =seconds + "s ";  // If the count down is over, write some text
            if (distance < 0 || second < 2 ) {
                clearInterval(x);
                document.querySelector(".forgot-item").innerHTML = "<label>Don't get code? <a href='{{ setRoute('user.resend.code') }}' class='text--base'>Resend</a></label>";
            }
            second--
        }, 1000);
    }
    resetTime();




    document.addEventListener('DOMContentLoaded', function() {
        const pinForm = document.querySelector('.pin-verification-form');
        const pinInput = document.querySelector('.pin-code-input');
        const pinError = document.querySelector('.pin-code-error');
        
        // Allow only numbers when typing
        pinInput.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
            
            // Limit to 6 digits
            if (this.value.length > 6) {
                this.value = this.value.slice(0, 6);
            }
            
            // Show/hide error message based on current input
            if (this.value.length !== 0 && this.value.length !== 6) {
                pinError.style.display = 'block';
            } else {
                pinError.style.display = 'none';
            }
        });
        
        // Form submission validation
        pinForm.addEventListener('submit', function(event) {
            // Validate PIN has exactly 6 digits
            if (pinInput.value.length !== 6 || !/^\d{6}$/.test(pinInput.value)) {
                event.preventDefault();
                pinError.style.display = 'block';
                pinInput.focus();
            }
        });
    });
</script>
@endpush