@extends('user.layouts.master') 
@section('breadcrumb')
    @include('user.components.breadcrumb',['breadcrumbs' => [
        [
            'name'  => __("Dashboard"),
            'url'   => setRoute("user.dashboard"),
        ]
    ], 'active' => __("Policies")])
@endsection
@push('css')
    <style>
        .text-capitalize{
            text-transform: capitalize;
        }
        .table{
            color: white;
        }
    </style>
@endpush
@section('content')
    <div class="body-wrapper">
        <div class="table-area mt-20">
            <div class="table-wrapper">
                <div class="dashboard-header-wrapper">
                    <h4 class="title">{{ __("Policies") }}</h4>
                    @if(auth()->user()->type == "seller")
                        <div class="dashboard-btn-wrapper">
                            <div class="dashboard-btn">
                                <a href="{{ setRoute('user.policies.create') }}" class="btn--base"><i class="las la-plus me-1"></i> {{ __("Create New Policy") }}</a>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="container">
                    <div class="row">
                        <div class="col-lg-12 margin-tb">
                            <div class="float-start">
                                
                            </div>
                            <div class="float-end">
                                <a class="btn btn-primary" href="{{ route('user.policies.index') }}">Back</a>
                            </div>
                        </div>
                    </div>
                
                    <div class="row mt-3">
                        <div class="col-xs-12 col-sm-12 col-md-12">
                            <div class="form-group">
                                <strong>Name:</strong>
                                {{ $policy->name }}
                            </div>
                        </div>
                        <div class="col-xs-12 col-sm-12 col-md-12">
                            <div class="form-group">
                                <strong>Description:</strong>
                                {{ $policy->description }}
                            </div>
                        </div>
                        <div class="col-xs-12 col-sm-12 col-md-12">
                            <div class="form-group">
                                <strong>Policy Details:</strong>
                                <div class="table-responsive mt-3">
                                    <table class="table table-bordered">
                                        <tbody>
                                            @php
                                                $fields = $policy->fields;
                                            @endphp
                                            
                                            @if(!empty($fields))
                                                @if(isset($fields['delivery_fee_payer']))
                                                <tr>
                                                    <td><strong>Delivery Fee Paid By:</strong></td>
                                                    <td>{{ ucfirst($fields['delivery_fee_payer']) }}</td>
                                                </tr>
                                                @endif
                                                
                                                @if(isset($fields['return_fee_payer']))
                                                <tr>
                                                    <td><strong>Return Fee Paid By:</strong></td>
                                                    <td>{{ ucfirst($fields['return_fee_payer']) }}</td>
                                                </tr>
                                                @endif
                                                
                                                @if(isset($fields['has_advanced_payment']))
                                                <tr>
                                                    <td><strong>Advanced Payment:</strong></td>
                                                    <td>{{ $fields['has_advanced_payment'] == 1 ? 'Yes, included' : 'Not required' }}</td>
                                                </tr>
                                                @endif
                                                
                                                @if(isset($fields['escrow_amount_payer']))
                                                <!-- <tr>
                                                    <td><strong>Escrow Amount Paid By:</strong></td>
                                                    <td>{{ ucfirst($fields['escrow_amount_payer']) }}</td>
                                                </tr> -->
                                                @endif
                                                
                                                @if(isset($fields['has_delivery_timeframe']))
                                                <tr>
                                                    <td><strong>Delivery Timeframe:</strong></td>
                                                    <td>{{ $fields['has_delivery_timeframe'] == 1 ? 'Yes, included' : 'Not required' }}</td>
                                                </tr>
                                                @endif
                                            @else
                                                <tr>
                                                    <td colspan="2">No additional policy details specified</td>
                                                </tr>
                                            @endif
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-xs-12 col-sm-12 col-md-12">
                            <div class="form-group">
                                <strong>Created At:</strong>
                                {{ $policy->created_at->format('d/m/Y H:i:s') }}
                            </div>
                        </div>
                        <div class="col-xs-12 col-sm-12 col-md-12">
                            <div class="form-group">
                                <strong>Updated At:</strong>
                                {{ $policy->updated_at->format('d/m/Y H:i:s') }}
                            </div>
                        </div>
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