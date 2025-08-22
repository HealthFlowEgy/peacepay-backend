@extends('user.layouts.master') 
@section('breadcrumb')
    @include('user.components.breadcrumb',['breadcrumbs' => [
        [
            'name'  => __("Dashboard"),
            'url'   => setRoute("user.dashboard"),
        ]
    ], 'active' => __("My Escrow")])
@endsection
@push('css')
    <style>
        .text-capitalize{
            text-transform: capitalize;
        }
    </style>
@endpush
@section('content')
    <div class="body-wrapper">
        <div class="table-area mt-20">
            <div class="table-wrapper">
                <div class="dashboard-header-wrapper">
                    <h4 class="title">{{ __("My Escrow") }}</h4>
                    @if(auth()->user()->type == "seller")
                        <div class="dashboard-btn-wrapper">
                            <div class="dashboard-btn">
                                <a href="{{ setRoute('user.my-escrow.add') }}" class="btn--base"><i class="las la-plus me-1"></i> {{ __("Create New Escrow") }}</a>
                            </div>
                        </div>
                    @endif
                </div>
                
                <!-- Search and Filter Section -->
                <div class="search-filter-wrapper mb-3">
                    <div class="row">
                        <div class="col-xl-3 col-lg-4 col-md-6 mb-3">
                            <div class="form-group">
                                <label>{{ __("Search") }}</label>
                                <input type="text" class="form-control" name="search" placeholder="{{ __('Search by ID, Title...') }}" value="{{ request('search') }}">
                            </div>
                        </div>
                        <div class="col-xl-2 col-lg-3 col-md-6 mb-3">
                            <div class="form-group">
                                <label>{{ __("Status") }}</label>
                                <select class="form-control" name="status_filter">
                                    <option value="">{{ __('All Status') }}</option>
                                    <option value="1" {{ request('status_filter') == '1' ? 'selected' : '' }}>{{ __('Approval Pending') }}</option>
                                    <option value="2" {{ request('status_filter') == '2' ? 'selected' : '' }}>{{ __('Payment Waiting') }}</option>
                                    <option value="3" {{ request('status_filter') == '3' ? 'selected' : '' }}>{{ __('On going') }}</option>
                                    <option value="4" {{ request('status_filter') == '4' ? 'selected' : '' }}>{{ __('Released') }}</option>
                                    <option value="5" {{ request('status_filter') == '5' ? 'selected' : '' }}>{{ __('Disputed') }}</option>
                                    <option value="6" {{ request('status_filter') == '6' ? 'selected' : '' }}>{{ __('Canceled') }}</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-xl-2 col-lg-3 col-md-6 mb-3">
                            <div class="form-group">
                                <label>{{ __("From Date") }}</label>
                                <input type="date" class="form-control" name="date_from" placeholder="{{ __('From Date') }}" value="{{ request('date_from') }}">
                            </div>
                        </div>
                        <div class="col-xl-2 col-lg-3 col-md-6 mb-3">
                            <div class="form-group">
                                <label>{{ __("From Date") }}</label>
                                <input type="date" class="form-control" name="date_to" placeholder="{{ __('To Date') }}" value="{{ request('date_to') }}">
                            </div>
                        </div>
                        <div class="col-xl-2 col-lg-3 col-md-6 mb-3">
                            <div class="form-group">
                                <label>{{ __("Sort") }}</label>
                                <select class="form-control" name="amount_sort">
                                    <option value="">{{ __('Sort by Amount') }}</option>
                                    <option value="asc" {{ request('amount_sort') == 'asc' ? 'selected' : '' }}>{{ __('Low to High') }}</option>
                                    <option value="desc" {{ request('amount_sort') == 'desc' ? 'selected' : '' }}>{{ __('High to Low') }}</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-xl-2 col-lg-2 col-md-6 mb-3">
                            <button type="button" class="btn btn--base" id="searchBtn">
                                <i class="las la-search"></i> {{ __('Search') }}
                            </button>
                        </div>
                        <div class="col-xl-2 col-lg-2 col-md-6 mb-3">
                            <button type="button" class="btn btn--base bg--danger clear-search">
                                <i class="las la-times"></i> {{ __('Clear') }}
                            </button>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>{{ __("Escrow Id") }}</th>
                                <th>{{ __("Title") }}</th>
                                <th>{{ __("Amount") }}</th>
                                <th>{{ __("Status") }}</th>
                                <th>{{ __("Created At") }}</th> 
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($escrowData as $item)
                            <tr>
                                <td>{{ $item->escrow_id}}</td>
                                <td>{{ substr($item->title,0,35)."..." }}</td>
                                <td>{{ get_amount($item->amount, $item->escrow_currency) }}</td>
                                <td><span class="{{ $item->string_status->class}}">{{ $item->string_status->value}}</span></td>
                                <td>{{ $item->created_at->format('Y-m-d') }}</td>
                                <td>
                                    @if ($item->buyer_or_seller_id == auth()->user()->id && $item->status == escrow_const()::APPROVAL_PENDING)
                                    <a href="{{ setRoute('user.escrow-action.paymentApprovalPending', encrypt($item->id))}}" class="btn btn--base bg--warning"><i class="las la-expand"></i></a>
                                    <a href="{{ setRoute('user.escrow-action.paymentCancel', encrypt($item->id))}}" class="btn btn--base bg--danger">{{__('Cancel')}}</a>
                                    @endif
                                    @if ($item->user_id == auth()->user()->id && $item->opposite_role == "buyer" && $item->status == escrow_const()::PAYMENT_WATTING)
                                    <a href="{{ setRoute('user.my-escrow.payment.crypto.address', $item->escrow_id)}}" class="btn btn--base bg--warning"><i class="las la-expand"></i></a>
                                    @endif
                                    @if ($item->user_id != auth()->user()->id && $item->opposite_role == "buyer" && $item->status == escrow_const()::PAYMENT_WATTING)
                                    <a href="{{ setRoute('user.escrow-action.payment.crypto.address', $item->escrow_id)}}" class="btn btn--base bg--warning"><i class="las la-expand"></i></a>
                                    @endif
                                    {{-- escrow conversation button  --}}
                                    <a href="{{ setRoute('user.escrow-action.escrowConversation', encrypt($item->id))}}" class="btn btn--base chat-btn"><i class="las la-comment"></i>  
                                        @php
                                            $count = 0;
                                        @endphp 
                                        @foreach ($item->conversations as $conversation)
                                            @if ($conversation->seen == 0 && $conversation->sender != auth()->user()->id)
                                                @php
                                                    $count++;
                                                @endphp
                                            @endif
                                        @endforeach
                                        @if ($count > 0) 
                                        <span class="dot"></span>
                                        @endif
                                    </a>
                                    {{-- end escrow conversation button  --}}

                                    @if(auth()->user()->type == 'seller')
                                        <div class="btn-group share-buttons">
                                            <button onclick="copyToClipboard('{{ setRoute('user.escrow-action.paymentApprovalPending', encrypt($item->id)) }}')" class="btn btn--base bg--primary" title="Copy Link"><i class="las la-copy"></i></button>
                                            <a href="https://wa.me/201143536496/?text={{ urlencode(__('Please Pay Using This link').' '.setRoute('user.escrow-action.paymentApprovalPending', encrypt($item->id))) }}" target="_blank" class="btn btn--base bg--success" title="Share on WhatsApp"><i class="lab la-whatsapp"></i></a>
                                        </div>
                                    @endif
                                </td>
                            </tr> 
                            @empty
                            <tr>
                                <td colspan="10"><div class="alert alert-primary" style="margin-top: 37.5px; text-align:center">{{ __("No data found!") }}</div></td>
                            </tr>
                            @endforelse 
                        </tbody>
                    </table>
                    {{ get_paginate($escrowData) }}
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script')
    <script>
        function copyToClipboard(text) {
            var tempInput = document.createElement("input");
            tempInput.value = text;
            document.body.appendChild(tempInput);
            tempInput.select();
            document.execCommand("copy");
            document.body.removeChild(tempInput);
            
            // Optional: Show a notification or tooltip that the link was copied
            alert("Link copied to clipboard!");
        }

        $(document).ready(function() {
            // Search functionality
            $('#searchBtn').on('click', function() {
                performSearch();
            });

            // Real-time search on input change
            $('input[name="search"]').on('keyup', function(e) {
                if (e.keyCode === 13) { // Enter key
                    performSearch();
                } else {
                    // Debounce search
                    clearTimeout(window.searchTimeout);
                    window.searchTimeout = setTimeout(function() {
                        performSearch();
                    }, 500);
                }
            });

            // Filter change events
            $('select[name="status_filter"], select[name="amount_sort"], input[name="date_from"], input[name="date_to"]').on('change', function() {
                performSearch();
            });

            function performSearch() {
                var searchData = {
                    search: $('input[name="search"]').val(),
                    status_filter: $('select[name="status_filter"]').val(),
                    date_from: $('input[name="date_from"]').val(),
                    date_to: $('input[name="date_to"]').val(),
                    amount_sort: $('select[name="amount_sort"]').val()
                };

                // Update URL with search parameters
                var url = new URL(window.location.href);
                Object.keys(searchData).forEach(key => {
                    if (searchData[key]) {
                        url.searchParams.set(key, searchData[key]);
                    } else {
                        url.searchParams.delete(key);
                    }
                });
                window.history.pushState({}, '', url);

                // Perform AJAX search
                $.ajax({
                    url: "{{ setRoute('user.my-escrow.search') }}",
                    type: 'GET',
                    data: searchData,
                    beforeSend: function() {
                        $('.custom-table tbody').html('<tr><td colspan="6" class="text-center"><i class="las la-spinner la-spin"></i> {{ __("Searching...") }}</td></tr>');
                    },
                    success: function(response) {
                        $('.custom-table tbody').html(response);
                    },
                    error: function() {
                        $('.custom-table tbody').html('<tr><td colspan="6" class="text-center text-danger">{{ __("Error occurred while searching") }}</td></tr>');
                    }
                });
            }

            // Clear search
            $(document).on('click', '.clear-search', function() {
                $('input[name="search"]').val('');
                $('select[name="status_filter"]').val('');
                $('input[name="date_from"]').val('');
                $('input[name="date_to"]').val('');
                $('select[name="amount_sort"]').val('');
                performSearch();
            });
        });
    </script>
@endpush