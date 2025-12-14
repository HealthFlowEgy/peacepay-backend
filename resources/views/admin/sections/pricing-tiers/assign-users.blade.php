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
                            <th>{{ __("Current Tier") }}</th>
                            <th>{{ __("Assign Tier") }}</th>
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
                                <td>
                                    @if($user->pricingTier)
                                        <span class="badge badge--info">{{ $user->pricingTier->name }}</span>
                                    @else
                                        <span class="badge badge--secondary">{{ __("Default") }}</span>
                                    @endif
                                </td>
                                <td>
                                    <form class="tier-assign-form" method="POST" action="{{ setRoute('admin.pricing.tiers.assign.user') }}">
                                        @csrf
                                        <input type="hidden" name="user_id" value="{{ $user->id }}">
                                        <select name="pricing_tier_id" class="form--control tier-select" data-user="{{ $user->id }}">
                                            <option value="">{{ __("Default Pricing") }}</option>
                                            @foreach($pricing_tiers as $tier)
                                                <option value="{{ $tier->id }}" {{ $user->pricing_tier_id == $tier->id ? 'selected' : '' }}>
                                                    {{ $tier->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </form>
                                </td>
                                <td>
                                    <button type="button" class="btn btn--base btn--sm assign-tier-btn" data-user="{{ $user->id }}">
                                        {{ __("Assign") }}
                                    </button>
                                </td>
                            </tr>
                        @empty
                            @include('admin.components.alerts.empty',['colspan' => 6])
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        {{ get_paginate($users) }}
    </div>

@endsection

@push('script')
    <script>
        $(".assign-tier-btn").click(function(){
            var userId = $(this).data('user');
            var form = $(this).closest('tr').find('.tier-assign-form');
            form.submit();
        });

        // Auto-submit on select change
        $(".tier-select").change(function(){
            var userId = $(this).data('user');
            var form = $(this).closest('tr').find('.tier-assign-form');
            form.submit();
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
