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
                                <label>Name:</label>
                                <input type="text" name="name" class="form--control" placeholder="Name" value="{{ old('name') }}">
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-xs-12 col-sm-12 col-md-12">
                                <div class="form-group">
                                    <label>Description:</label>
                                    <textarea class="form--control" style="height:150px" name="description" placeholder="Description">{{ old('description') }}</textarea>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Payment Responsibility Section -->
                        <div class="row mb-3">
                            <div class="col-12">
                                <h4>Payment Responsibilities</h4>
                            </div>
                            
                            <!-- Delivery Fee Responsibility -->
                            <div class="col-md-6 form-group">
                                <label>Delivery Fee Paid By:</label>
                                <select name="fields[delivery_fee_payer]" class="form--control">
                                    <option value="buyer" {{ old('fields.delivery_fee_payer') == 'buyer' ? 'selected' : '' }}>Buyer</option>
                                    <option value="seller" {{ old('fields.delivery_fee_payer') == 'seller' ? 'selected' : '' }}>Seller</option>
                                </select>
                            </div>
                            
                            <!-- Return Fee Responsibility -->
                            <div class="col-md-6 form-group">
                                <label>Return Fee Paid By:</label>
                                <select name="fields[return_fee_payer]" class="form--control">
                                    <option value="buyer" {{ old('fields.return_fee_payer') == 'buyer' ? 'selected' : '' }}>Buyer</option>
                                    <option value="seller" {{ old('fields.return_fee_payer') == 'seller' ? 'selected' : '' }}>Seller</option>
                                </select>
                            </div>
                            
                            <!-- Advanced Payment Responsibility -->
                            <div class="col-md-6 form-group">
                                <label>Advanced Payment Paid By:</label>
                                <select name="fields[advanced_payment_payer]" class="form--control">
                                    <option value="buyer" {{ old('fields.advanced_payment_payer') == 'buyer' ? 'selected' : '' }}>Buyer</option>
                                    <option value="seller" {{ old('fields.advanced_payment_payer') == 'seller' ? 'selected' : '' }}>Seller</option>
                                </select>
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
                            <div class="col-md-6 form-group">
                                <label>Delivery Timeframe:</label>
                                <div class="radio-wrapper">
                                    <div class="radio-item">
                                        <input class="" type="radio" name="fields[has_delivery_timeframe]" id="timeframe_yes" value="1" {{ old('fields.has_delivery_timeframe') == '1' ? 'checked' : '' }}>
                                        <label class="" for="timeframe_yes">
                                            Yes, include delivery timeframe
                                        </label>
                                    </div>
                                    <div class="radio-item">
                                        <input class="" type="radio" name="fields[has_delivery_timeframe]" id="timeframe_no" value="0" {{ old('fields.has_delivery_timeframe') == '0' ? 'checked' : '' }}>
                                        <label class="" for="timeframe_no">
                                            No delivery timeframe required
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-xs-12 col-sm-12 col-md-12 text-center">
                                <button type="submit" class="btn btn-primary">Submit</button>
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