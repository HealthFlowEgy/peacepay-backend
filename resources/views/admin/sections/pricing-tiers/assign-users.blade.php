@extends('admin.layouts.master')

@push('css')
@endpush

@section('page-title')
    @include('admin.components.page-title',['title' => __($page_title)])
@endsection

@section('breadcrumb')
    @include('admin.components.breadcrumb',['breadcrumbs' => [
        [
            'name'  => __("Dashboard"),
            'url'   => setRoute("admin.dashboard"),
        ]
    ], 'active' => __("Assign Users to Pricing Tiers")])
@endsection

@section('content')
    <div class="table-area">
        <div class="table-wrapper">
            <div class="table-header">
                <h5 class="title">{{ __("Assign Users to Pricing Tiers") }}</h5>
                <div class="table-btn-area">
                    @include('admin.components.search-input',[
                        'name'  => 'user_search',
                    ])
                </div>
            </div>
            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>{{ __("User") }}</th>
                            <th>{{ __("Email") }}</th>
                            <th>{{ __("Type") }}</th>
                            <th>{{ __("Delivery Tier") }}</th>
                            <th>{{ __("Merchant Tier") }}</th>
                            <th>{{ __("Cash Out Tier") }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($users as $user)
                            <tr>
                                <td>{{ $user->fullname }}</td>
                                <td>{{ $user->email }}</td>
                                <td>
                                    <span class="badge badge--{{ $user->type == 'seller' ? 'success' : ($user->type == 'buyer' ? 'primary' : 'warning') }}">
                                        {{ ucfirst($user->type) }}
                                    </span>
                                </td>

                                {{-- Delivery Tier --}}
                                <td>
                                    @php
                                        $deliveryTier = $user->pricingTiers->where('type', 'delivery')->first();
                                    @endphp
                                    @if($deliveryTier)
                                        <span class="badge badge--primary">{{ $deliveryTier->name }}</span>
                                    @else
                                        <span class="badge badge--secondary">{{ __("Default") }}</span>
                                    @endif
                                </td>

                                {{-- Merchant Tier --}}
                                <td>
                                    @php
                                        $merchantTier = $user->pricingTiers->where('type', 'merchant')->first();
                                    @endphp
                                    @if($merchantTier)
                                        <span class="badge badge--success">{{ $merchantTier->name }}</span>
                                    @else
                                        <span class="badge badge--secondary">{{ __("Default") }}</span>
                                    @endif
                                </td>

                                {{-- Cash Out Tier --}}
                                <td>
                                    @php
                                        $cashOutTier = $user->pricingTiers->where('type', 'cash_out')->first();
                                    @endphp
                                    @if($cashOutTier)
                                        <span class="badge badge--warning">{{ $cashOutTier->name }}</span>
                                    @else
                                        <span class="badge badge--secondary">{{ __("Default") }}</span>
                                    @endif
                                </td>

                                <td>
                                    <button type="button" class="btn btn--base btn--sm assign-tier-btn" data-user="{{ $user->id }}" data-user-name="{{ $user->fullname }}">
                                        {{ __("Manage Tiers") }}
                                    </button>
                                </td>
                            </tr>
                        @empty
                            @include('admin.components.alerts.empty',['colspan' => 7])
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        {{ get_paginate($users) }}
    </div>

    {{-- Assign Tiers Modal --}}
    <div id="assign-tiers-modal" class="mfp-hide large">
        <div class="modal-data">
            <div class="modal-header px-0">
                <h5 class="modal-title">{{ __("Assign Pricing Tiers") }} - <span id="modal-user-name"></span></h5>
            </div>
            <div class="modal-form-data">
                <input type="hidden" id="modal-user-id" value="">

                {{-- Delivery Tier Section --}}
                <div class="card mb-3">
                    <div class="card-header">
                        <h6 class="mb-0">{{ __("Delivery Fees Tier") }}</h6>
                    </div>
                    <div class="card-body">
                        <form class="tier-assign-form" method="POST" action="{{ setRoute('admin.pricing.tiers.assign.user') }}" data-tier-type="delivery">
                            @csrf
                            <input type="hidden" name="user_id" class="form-user-id">
                            <input type="hidden" name="tier_type" value="delivery">
                            <div class="form-group">
                                <select name="pricing_tier_id" class="form--control select2-basic">
                                    <option value="">{{ __("Use Default Fees") }}</option>
                                    @if(isset($pricing_tiers['delivery']))
                                        @foreach($pricing_tiers['delivery'] as $tier)
                                            <option value="{{ $tier->id }}">{{ $tier->name }} ({{ get_amount($tier->percent_charge) }}% + {{ get_amount($tier->fixed_charge) }})</option>
                                        @endforeach
                                    @endif
                                </select>
                            </div>
                            <button type="submit" class="btn btn--base btn--sm">{{ __("Update Delivery Tier") }}</button>
                        </form>
                    </div>
                </div>

                {{-- Merchant Tier Section --}}
                <div class="card mb-3">
                    <div class="card-header">
                        <h6 class="mb-0">{{ __("Merchant Fees Tier") }}</h6>
                    </div>
                    <div class="card-body">
                        <form class="tier-assign-form" method="POST" action="{{ setRoute('admin.pricing.tiers.assign.user') }}" data-tier-type="merchant">
                            @csrf
                            <input type="hidden" name="user_id" class="form-user-id">
                            <input type="hidden" name="tier_type" value="merchant">
                            <div class="form-group">
                                <select name="pricing_tier_id" class="form--control select2-basic">
                                    <option value="">{{ __("Use Default Fees") }}</option>
                                    @if(isset($pricing_tiers['merchant']))
                                        @foreach($pricing_tiers['merchant'] as $tier)
                                            <option value="{{ $tier->id }}">{{ $tier->name }} ({{ get_amount($tier->percent_charge) }}% + {{ get_amount($tier->fixed_charge) }})</option>
                                        @endforeach
                                    @endif
                                </select>
                            </div>
                            <button type="submit" class="btn btn--base btn--sm">{{ __("Update Merchant Tier") }}</button>
                        </form>
                    </div>
                </div>

                {{-- Cash Out Tier Section --}}
                <div class="card mb-3">
                    <div class="card-header">
                        <h6 class="mb-0">{{ __("Cash Out Fees Tier") }}</h6>
                    </div>
                    <div class="card-body">
                        <form class="tier-assign-form" method="POST" action="{{ setRoute('admin.pricing.tiers.assign.user') }}" data-tier-type="cash_out">
                            @csrf
                            <input type="hidden" name="user_id" class="form-user-id">
                            <input type="hidden" name="tier_type" value="cash_out">
                            <div class="form-group">
                                <select name="pricing_tier_id" class="form--control select2-basic">
                                    <option value="">{{ __("Use Default Fees") }}</option>
                                    @if(isset($pricing_tiers['cash_out']))
                                        @foreach($pricing_tiers['cash_out'] as $tier)
                                            <option value="{{ $tier->id }}">{{ $tier->name }} ({{ get_amount($tier->percent_charge) }}% + {{ get_amount($tier->fixed_charge) }})</option>
                                        @endforeach
                                    @endif
                                </select>
                            </div>
                            <button type="submit" class="btn btn--base btn--sm">{{ __("Update Cash Out Tier") }}</button>
                        </form>
                    </div>
                </div>

                <div class="mt-3 text-right">
                    <button type="button" class="btn btn--danger modal-close">{{ __("Close") }}</button>
                </div>
            </div>
        </div>
    </div>

@endsection

@push('script')
    <script>
        $(".assign-tier-btn").click(function(){
            var userId = $(this).data('user');
            var userName = $(this).data('user-name');

            $("#modal-user-id").val(userId);
            $("#modal-user-name").text(userName);
            $(".form-user-id").val(userId);

            // Reset all selects to default
            $('form[data-tier-type="delivery"] select').val('').trigger('change');
            $('form[data-tier-type="merchant"] select').val('').trigger('change');
            $('form[data-tier-type="cash_out"] select').val('').trigger('change');

            // Load current tiers for this user via AJAX
            $.ajax({
                url: "{{ setRoute('admin.pricing.tiers.get.user.tiers') }}",
                type: "GET",
                data: { user_id: userId },
                success: function(response) {
                    if(response.success) {
                        // Set delivery tier
                        if(response.data.delivery_tier_id) {
                            $('form[data-tier-type="delivery"] select').val(response.data.delivery_tier_id).trigger('change');
                        }

                        // Set merchant tier
                        if(response.data.merchant_tier_id) {
                            $('form[data-tier-type="merchant"] select').val(response.data.merchant_tier_id).trigger('change');
                        }

                        // Set cash out tier
                        if(response.data.cash_out_tier_id) {
                            $('form[data-tier-type="cash_out"] select').val(response.data.cash_out_tier_id).trigger('change');
                        }
                    }
                }
            });

            openModalBySelector("#assign-tiers-modal");
        });

        // Search functionality
        $("input[name=user_search]").on('keyup', function() {
            var value = $(this).val().toLowerCase();
            $("table tbody tr").filter(function() {
                $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
            });
        });
    </script>
@endpush
