@extends('user.layouts.master')  
@section('breadcrumb')
    @include('user.components.breadcrumb',['breadcrumbs' => [
        [
            'name'  => __("Dashboard"),
            'url'   => setRoute("user.dashboard"),
        ]
    ], 'active' => __("Create New Policy")])
@endsection

@section('content')
<div class="body-wrapper">
    <div class="row mt-20 mb-20-none">
        <div class="col-xl-12 col-lg-12 mb-20">
            <div class="custom-card mt-10">
                <div class="dashboard-header-wrapper">
                    <h4 class="title">{{ __("Create New Policy") }}</h4>
                </div>
                <div class="card-body">
                    <form action="{{ setRoute('user.policies.store')}}" class="card-form policy-form" method="POST" enctype="multipart/form-data">
                        @csrf
                        
                        <div class="row">
                            <div class="col-xl-6 col-lg-6 form-group">
                                <label>{{ __('Name') }}:</label>
                                <input type="text" name="name" class="form--control" placeholder="Name" value="{{ old('name') }}">
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-xs-12 col-sm-12 col-md-12">
                                <div class="form-group">
                                    <label>{{ __('Description') }}:</label>
                                    <textarea class="form--control" style="height:150px" name="description" placeholder="Description">{{ old('description') }}</textarea>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Payment Responsibility Section -->
                        <div class="row mb-3">
                            <div class="col-12">
                                <h4>{{ __('Payment Responsibilities') }}</h4>
                            </div>
                            
                            <!-- Delivery Fee Responsibility -->
                            <div class="col-md-6 form-group">
                                <label>{{__('Delivery Fee Paid By')}}:</label>
                                <select name="fields[delivery_fee_payer]" class="form--control">
                                    <option value="buyer" {{ old('fields.delivery_fee_payer') == 'buyer' ? 'selected' : '' }}>{{ __('Buyer') }}</option>
                                    <option value="seller" {{ old('fields.delivery_fee_payer') == 'seller' ? 'selected' : '' }}>{{ __('Seller') }}</option>
                                </select>
                            </div>
                            
                            <!-- Return Fee Responsibility -->
                            <!-- <div class="col-md-6 form-group">
                                <label>{{ __('Return Fee Paid By') }}:</label>
                                <select name="fields[return_fee_payer]" class="form--control">
                                    <option value="buyer" {{ old('fields.return_fee_payer') == 'buyer' ? 'selected' : '' }}>{{ __('Buyer') }}</option>
                                    <option value="seller" {{ old('fields.return_fee_payer') == 'seller' ? 'selected' : '' }}>{{ __('Seller') }}</option>
                                </select>
                            </div> -->
                            
                            <!-- Advanced Payment Responsibility -->
                            <div class="col-md-6 form-group">
                                <label>{{ __('Advanced Payment Paid By') }}:</label>
                                <div class="radio-wrapper">
                                    <div class="radio-item">
                                        <input class="" type="radio" name="fields[has_advanced_payment]" id="has_advanced_payment_yes" value="1" {{ old('fields.has_advanced_payment') == '1' ? 'checked' : '' }}>
                                        <label class="" for="has_advanced_payment_yes">
                                            {{ __('Yes') }}
                                        </label>
                                    </div>
                                    <div class="radio-item">
                                        <input class="" type="radio" name="fields[has_advanced_payment]" id="has_advanced_payment_no" value="0" {{ old('fields.has_advanced_payment') == '0' ? 'checked' : '' }}>
                                        <label class="" for="has_advanced_payment_no">
                                            {{ __('No') }}
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Escrow Amount Responsibility -->
                            <!-- <div class="col-md-6 form-group">
                                <label>Escrow Amount Paid By:</label>
                                <select name="fields[escrow_amount_payer]" class="form--control">
                                    <option value="buyer" {{ old('fields.escrow_amount_payer') == 'buyer' ? 'selected' : '' }}>Buyer</option>
                                    <option value="seller" {{ old('fields.escrow_amount_payer') == 'seller' ? 'selected' : '' }}>Seller</option>
                                </select>
                            </div> -->
                            
                            <!-- Delivery Timeframe Option -->
                            <!-- <div class="col-md-6 form-group">
                                <label>{{ __('') }}Delivery Timeframe:</label>
                                <div class="radio-wrapper">
                                    <div class="radio-item">
                                        <input class="" type="radio" name="fields[has_delivery_timeframe]" id="timeframe_yes" value="1" {{ old('fields.has_delivery_timeframe') == '1' ? 'checked' : '' }}>
                                        <label class="" for="timeframe_yes">
                                            {{ __('Yes, include delivery timeframe') }}
                                        </label>
                                    </div>
                                    <div class="radio-item">
                                        <input class="" type="radio" name="fields[has_delivery_timeframe]" id="timeframe_no" value="0" {{ old('fields.has_delivery_timeframe') == '0' ? 'checked' : '' }}>
                                        <label class="" for="timeframe_no">
                                            {{ __('No delivery timeframe required') }}
                                        </label>
                                    </div>
                                </div>
                            </div> -->

                            <!-- Advanced Payment Responsibility -->
                            <div class="col-md-6 form-group">
                                <label>{{ __('Is paid by DSP') }}:</label>
                                <div class="radio-wrapper">
                                    <div class="radio-item">
                                        <input class="" type="radio" name="fields[is_paid_by_dsp]" id="is_paid_by_dsp_yes" value="1" {{ old('fields.is_paid_by_dsp') == '1' ? 'checked' : '' }}>
                                        <label class="" for="is_paid_by_dsp_yes">
                                            {{ __('Yes') }}
                                        </label>
                                    </div>
                                    <div class="radio-item">
                                        <input class="" type="radio" name="fields[is_paid_by_dsp]" id="is_paid_by_dsp_no" value="0" {{ old('fields.is_paid_by_dsp') == '0' ? 'checked' : '' }}>
                                        <label class="" for="is_paid_by_dsp_no">
                                            {{ __('No') }}
                                        </label>
                                    </div>
                                </div>
                            </div>

                        </div>
                        
                        <div class="row">
                            <div class="col-xs-12 col-sm-12 col-md-12 text-center">
                                <button type="submit" class="btn btn-primary">{{ __('Submit') }}</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div> 
@endsection
@push('script')
    <script>

    </script>
@endpush