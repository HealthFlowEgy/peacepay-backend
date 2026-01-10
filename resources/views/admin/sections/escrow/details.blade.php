@extends('admin.layouts.master')

@push('css')
<style>
    .status-badge {
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
    }
    .status-pending { background: #fef3cd; color: #856404; }
    .status-active { background: #d1ecf1; color: #0c5460; }
    .status-completed { background: #d4edda; color: #155724; }
    .status-cancelled { background: #f8d7da; color: #721c24; }
    .status-disputed { background: #fff3cd; color: #856404; }
    
    .fee-breakdown {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 15px;
        margin-top: 15px;
    }
    .fee-row {
        display: flex;
        justify-content: space-between;
        padding: 8px 0;
        border-bottom: 1px solid #e9ecef;
    }
    .fee-row:last-child {
        border-bottom: none;
        font-weight: bold;
    }
    .fee-label { color: #6c757d; }
    .fee-value { font-weight: 500; }
    .fee-value.positive { color: #28a745; }
    .fee-value.negative { color: #dc3545; }
    
    .action-buttons {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 20px;
    }
    .action-buttons .btn {
        flex: 1;
        min-width: 150px;
    }
    
    .timeline-item {
        position: relative;
        padding-left: 30px;
        padding-bottom: 20px;
        border-left: 2px solid #e9ecef;
    }
    .timeline-item:last-child {
        border-left: none;
    }
    .timeline-item::before {
        content: '';
        position: absolute;
        left: -6px;
        top: 0;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background: #6c757d;
    }
    .timeline-item.completed::before {
        background: #28a745;
    }
    .timeline-item.active::before {
        background: #007bff;
    }
    .timeline-item.cancelled::before {
        background: #dc3545;
    }
    
    .party-card {
        background: #fff;
        border: 1px solid #e9ecef;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 15px;
    }
    .party-card h6 {
        color: #6c757d;
        font-size: 12px;
        text-transform: uppercase;
        margin-bottom: 10px;
    }
    .party-info {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .party-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: #e9ecef;
        display: flex;
        align-items: center;
        justify-content: center;
    }
</style>
@endpush

@section('page-title')
    @include('admin.components.page-title', ['title' => __($page_title)])
@endsection

@section('breadcrumb')
    @include('admin.components.breadcrumb', [
        'breadcrumbs' => [
            [
                'name' => __('Dashboard'),
                'url' => setRoute('admin.dashboard'),
            ],
            [
                'name' => __('PeaceLinks'),
                'url' => setRoute('admin.escrow.index'),
            ],
        ],
        'active' => __('PeaceLink Details'),
    ])
@endsection

@section('content')

{{-- Main Details Card --}}
<div class="custom-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="title">{{ __("PeaceLink Details") }} - #{{ @$escrows->escrow_id }}</h6>
        <span class="status-badge status-{{ strtolower(@$escrows->stringStatus->value) }}">
            {{ @$escrows->stringStatus->value }}
        </span>
    </div>
    <div class="card-body">
        <div class="row">
            {{-- Left Column: Basic Info --}}
            <div class="col-xl-4 col-lg-4">
                <ul class="user-profile-list-two">
                    <li class="one">{{ __("Created")}}: <span>{{ @$escrows->created_at->format('d-m-Y h:i A') }}</span></li>
                    <li class="two">{{ __("PeaceLink ID")}}: <span>{{ @$escrows->escrow_id }}</span></li> 
                    <li class="three">{{ __("Category")}}: <span>{{ @$escrows->escrowCategory->name ?? 'N/A' }}</span></li> 
                    <li class="four">{{ __("Item Price")}}: <span class="text-primary">{{ @get_amount($escrows->amount, $escrows->escrow_currency) }}</span></li> 
                    <li class="five">{{ __("Charge Payer")}}: <span>{{ @$escrows->string_who_will_pay->value }}</span></li>
                    @if($escrows->delivery_timeframe)
                    <li class="six">{{ __("Delivery Timeframe")}}: <span>{{ @$escrows->delivery_timeframe }} hours</span></li>
                    @endif
                </ul>
            </div>

            {{-- Center Column: Parties --}}
            <div class="col-xl-4 col-lg-4">
                {{-- Buyer --}}
                <div class="party-card">
                    <h6>{{ __("Buyer") }}</h6>
                    <div class="party-info">
                        <div class="party-avatar">
                            <i class="las la-user"></i>
                        </div>
                        <div>
                            <strong>{{ @$escrows->user->fullname ?? @$escrows->user->username }}</strong>
                            <br>
                            <small class="text-muted">{{ @$escrows->user->mobile ?? @$escrows->user->email }}</small>
                        </div>
                    </div>
                </div>
                
                {{-- Merchant --}}
                <div class="party-card">
                    <h6>{{ __("Merchant") }}</h6>
                    <div class="party-info">
                        <div class="party-avatar">
                            <i class="las la-store"></i>
                        </div>
                        <div>
                            <strong>{{ @$escrows->seller->fullname ?? @$escrows->seller->username ?? 'Not Assigned' }}</strong>
                            <br>
                            <small class="text-muted">{{ @$escrows->seller->mobile ?? @$escrows->seller->email ?? '' }}</small>
                        </div>
                    </div>
                </div>
                
                {{-- DSP --}}
                @if($escrows->delivery_id)
                <div class="party-card">
                    <h6>{{ __("Delivery Service Provider (DSP)") }}</h6>
                    <div class="party-info">
                        <div class="party-avatar">
                            <i class="las la-truck"></i>
                        </div>
                        <div>
                            <strong>{{ @$escrows->delivery->fullname ?? @$escrows->delivery->username }}</strong>
                            <br>
                            <small class="text-muted">{{ @$escrows->delivery->mobile ?? @$escrows->delivery->email }}</small>
                            @if(@$escrows->escrowDetails->dsp_paid)
                            <br><span class="badge bg-success">{{ __("Paid") }}</span>
                            @else
                            <br><span class="badge bg-warning">{{ __("Pending Payment") }}</span>
                            @endif
                        </div>
                    </div>
                </div>
                @endif
            </div>

            {{-- Right Column: Financial Summary --}}
            <div class="col-xl-4 col-lg-4">
                <div class="fee-breakdown">
                    <h6 class="mb-3">{{ __("Financial Summary") }}</h6>
                    
                    <div class="fee-row">
                        <span class="fee-label">{{ __("Item Price") }}</span>
                        <span class="fee-value">{{ get_amount($escrows->amount, $escrows->escrow_currency) }}</span>
                    </div>
                    
                    <div class="fee-row">
                        <span class="fee-label">{{ __("Delivery Fee") }}</span>
                        <span class="fee-value">{{ get_amount($escrows->escrowDetails->delivery_fees ?? 0, $escrows->escrow_currency) }}</span>
                    </div>
                    
                    <div class="fee-row">
                        <span class="fee-label">{{ __("Platform Fee") }}</span>
                        <span class="fee-value negative">-{{ get_amount($escrows->escrowDetails->fee ?? 0, $escrows->escrow_currency) }}</span>
                    </div>
                    
                    @if($escrows->escrowDetails->merchant_fees)
                    <div class="fee-row">
                        <span class="fee-label">{{ __("Merchant Fee (1% + 3 EGP)") }}</span>
                        <span class="fee-value negative">-{{ get_amount($escrows->escrowDetails->merchant_fees, $escrows->escrow_currency) }}</span>
                    </div>
                    @endif
                    
                    <div class="fee-row">
                        <span class="fee-label">{{ __("Buyer Paid") }}</span>
                        <span class="fee-value">{{ get_amount($escrows->escrowDetails->buyer_pay ?? 0, $escrows->escrow_currency) }}</span>
                    </div>
                    
                    <div class="fee-row">
                        <span class="fee-label">{{ __("Merchant Gets") }}</span>
                        <span class="fee-value positive">{{ get_amount($escrows->escrowDetails->seller_get ?? 0, $escrows->escrow_currency) }}</span>
                    </div>
                    
                    @if($escrows->delivery_id)
                    <div class="fee-row">
                        <span class="fee-label">{{ __("DSP Gets") }}</span>
                        <span class="fee-value positive">{{ get_amount($escrows->escrowDetails->delivery_get ?? 0, $escrows->escrow_currency) }}</span>
                    </div>
                    @endif
                    
                    @if($escrows->escrowDetails->advanced_payment_amount)
                    <div class="fee-row" style="background: #e3f2fd; margin: 10px -15px; padding: 10px 15px;">
                        <span class="fee-label">{{ __("Advanced Payment") }} ({{ $escrows->escrowDetails->advanced_payment_percentage ?? 50 }}%)</span>
                        <span class="fee-value">{{ get_amount($escrows->escrowDetails->advanced_payment_amount, $escrows->escrow_currency) }}</span>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Item Details Card --}}
<div class="custom-card mt-15">
    <div class="card-header">
        <h6 class="title">{{ __("Item Information") }}</h6>
    </div>
    <div class="card-body">
        <h5><strong>{{ __("Title") }}:</strong> {{ $escrows->title }}</h5>
        <p><strong>{{ __("Description") }}:</strong> {{ $escrows->remark }}</p>
        
        @if($escrows->delivery_address)
        <p><strong>{{ __("Delivery Address") }}:</strong> {{ $escrows->delivery_address }}</p>
        @endif
        
        @foreach ($escrows->file ?? [] as $key => $item)
        <p><strong>{{ __("Attachment") }}</strong>: 
            <span class="text--info">
                <a href="{{ files_asset_path('escrow-temp-file') . "/" . $item->attachment }}" target="_blank">
                    {{ Str::words(json_decode($item->attachment_info)->original_base_name ?? "", 5, '...' . json_decode($item->attachment_info)->extension ?? "" ) }}
                </a>
            </span>
        </p>
        @endforeach
    </div>
</div>

{{-- Timeline Card --}}
<div class="custom-card mt-15">
    <div class="card-header">
        <h6 class="title">{{ __("Status Timeline") }}</h6>
    </div>
    <div class="card-body">
        <div class="timeline">
            @php
                $statuses = [
                    'created' => ['label' => 'Created', 'icon' => 'la-plus-circle'],
                    'approved' => ['label' => 'Buyer Approved & Paid', 'icon' => 'la-check-circle'],
                    'dsp_assigned' => ['label' => 'DSP Assigned', 'icon' => 'la-truck'],
                    'in_transit' => ['label' => 'In Transit', 'icon' => 'la-shipping-fast'],
                    'delivered' => ['label' => 'Delivered (OTP Confirmed)', 'icon' => 'la-box'],
                    'completed' => ['label' => 'Completed', 'icon' => 'la-check-double'],
                ];
                $currentStatus = $escrows->status;
            @endphp
            
            @foreach($statuses as $statusKey => $statusInfo)
                @php
                    $isCompleted = false;
                    $isActive = false;
                    // Determine if this status is completed or active based on current status
                @endphp
                <div class="timeline-item {{ $isCompleted ? 'completed' : ($isActive ? 'active' : '') }}">
                    <strong><i class="las {{ $statusInfo['icon'] }}"></i> {{ $statusInfo['label'] }}</strong>
                    @if($statusKey == 'created')
                    <br><small class="text-muted">{{ $escrows->created_at->format('d-m-Y h:i A') }}</small>
                    @endif
                </div>
            @endforeach
            
            @if(str_contains($escrows->stringStatus->value ?? '', 'Cancel'))
            <div class="timeline-item cancelled">
                <strong><i class="las la-times-circle"></i> {{ $escrows->stringStatus->value }}</strong>
                @if($escrows->cancelled_at)
                <br><small class="text-muted">{{ $escrows->cancelled_at->format('d-m-Y h:i A') }}</small>
                @endif
                @if($escrows->cancel_reason)
                <br><small class="text-danger">{{ __("Reason") }}: {{ $escrows->cancel_reason }}</small>
                @endif
            </div>
            @endif
            
            @if($escrows->stringStatus->value == 'Disputed')
            <div class="timeline-item cancelled">
                <strong><i class="las la-exclamation-triangle"></i> {{ __("Dispute Opened") }}</strong>
                @if($escrows->dispute_reason)
                <br><small class="text-warning">{{ __("Reason") }}: {{ $escrows->dispute_reason }}</small>
                @endif
            </div>
            @endif
        </div>
    </div>
</div>

{{-- Cancellation Details (if cancelled) --}}
@if(str_contains($escrows->stringStatus->value ?? '', 'Cancel'))
<div class="custom-card mt-15">
    <div class="card-header bg-danger text-white">
        <h6 class="title text-white">{{ __("Cancellation Details") }}</h6>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <p><strong>{{ __("Cancelled By") }}:</strong> {{ $escrows->cancelled_by ?? 'N/A' }}</p>
                <p><strong>{{ __("Reason") }}:</strong> {{ $escrows->cancel_reason ?? 'N/A' }}</p>
                <p><strong>{{ __("DSP Was Assigned") }}:</strong> {{ $escrows->delivery_id ? 'Yes' : 'No' }}</p>
            </div>
            <div class="col-md-6">
                <div class="fee-breakdown">
                    <h6>{{ __("Refund Breakdown") }}</h6>
                    @if($escrows->delivery_id)
                    <div class="fee-row">
                        <span class="fee-label">{{ __("Delivery Fee Deducted") }}</span>
                        <span class="fee-value negative">-{{ get_amount($escrows->escrowDetails->delivery_fees ?? 0, $escrows->escrow_currency) }}</span>
                    </div>
                    <div class="fee-row">
                        <span class="fee-label">{{ __("DSP Paid") }}</span>
                        <span class="fee-value positive">{{ get_amount($escrows->escrowDetails->delivery_get ?? 0, $escrows->escrow_currency) }}</span>
                    </div>
                    @endif
                    <div class="fee-row">
                        <span class="fee-label">{{ __("Buyer Refunded") }}</span>
                        <span class="fee-value positive">{{ get_amount($escrows->escrowDetails->refund_amount ?? $escrows->escrowDetails->buyer_pay, $escrows->escrow_currency) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

{{-- Admin Action Buttons --}}
@if(in_array($escrows->status, [2, 5, 6, 7])) {{-- Pending approval, Disputed, or needs admin action --}}
<div class="custom-card mt-15">
    <div class="card-header">
        <h6 class="title">{{ __("Admin Actions") }}</h6>
    </div>
    <div class="card-body">
        <div class="action-buttons">
            @if($escrows->status == 2) {{-- Pending manual payment approval --}}
            <button type="button" class="btn btn--base approvedBtn">
                <i class="las la-check"></i> {{ __("Approve Payment") }}
            </button>
            <button type="button" class="btn btn--danger rejectBtn">
                <i class="las la-times"></i> {{ __("Reject Payment") }}
            </button>
            @endif
            
            @if(in_array($escrows->status, [5, 6, 7])) {{-- Disputed or needs resolution --}}
            <button type="button" class="btn btn--success releaseToMerchantBtn">
                <i class="las la-store"></i> {{ __("Release to Merchant") }}
            </button>
            <button type="button" class="btn btn--info releaseToBuyerBtn">
                <i class="las la-user"></i> {{ __("Release to Buyer") }}
            </button>
            <button type="button" class="btn btn--warning splitPaymentBtn">
                <i class="las la-balance-scale"></i> {{ __("Split Payment") }}
            </button>
            @endif
        </div>
        
        @if($escrows->delivery_id && !@$escrows->escrowDetails->dsp_paid)
        <div class="alert alert-warning mt-3">
            <i class="las la-exclamation-triangle"></i>
            {{ __("Note: DSP has not been paid yet. Releasing to merchant will automatically pay the DSP.") }}
        </div>
        @endif
    </div>
</div>
@endif

{{-- Payment Approval Modal --}}
@if($escrows->status == 2)
<div class="modal fade" id="approvedModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header p-3">
                <h5 class="modal-title">{{ __("Approve Payment Confirmation") }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form class="modal-form" action="{{ setRoute('admin.escrow.manual.payment.approved') }}" method="POST"> 
                    @csrf
                    @method("PUT")
                    <input type="hidden" name="id" value="{{ @$escrows->id }}">
                    <p>{{ __("Are you sure you want to approve this payment?") }}</p>
                    <p class="text-muted">{{ __("This will activate the PeaceLink and hold the funds in escrow.") }}</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn--danger" data-bs-dismiss="modal">{{ __("Cancel") }}</button>
                <button type="submit" class="btn btn--base btn-loading">{{ __("Approve") }}</button>
            </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header p-3">
                <h5 class="modal-title">{{ __("Reject Payment Confirmation") }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form class="modal-form" action="{{ setRoute('admin.escrow.manual.payment.rejected') }}" method="POST">
                    @csrf
                    @method("PUT")
                    <input type="hidden" name="id" value="{{ @$escrows->id }}">
                    @include('admin.components.form.textarea',[
                        'label'         => 'Rejection Reason*',
                        'name'          => 'reject_reason',
                        'value'         => old('reject_reason')
                    ])
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn--danger" data-bs-dismiss="modal">{{ __("Cancel") }}</button>
                <button type="submit" class="btn btn--base">{{ __("Reject") }}</button>
            </div>
            </form>
        </div>
    </div>
</div>
@endif

{{-- Release to Merchant Modal --}}
<div class="modal fade" id="releaseToMerchantModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header p-3 bg-success text-white">
                <h5 class="modal-title text-white">{{ __("Release to Merchant") }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form class="modal-form" action="{{ setRoute('admin.escrow.release.to.merchant') }}" method="POST">
                    @csrf
                    @method("PUT")
                    <input type="hidden" name="id" value="{{ @$escrows->id }}">
                    
                    <div class="alert alert-info">
                        <strong>{{ __("Amount to Merchant") }}:</strong> {{ get_amount($escrows->escrowDetails->seller_get ?? 0, $escrows->escrow_currency) }}
                        @if($escrows->delivery_id)
                        <br><strong>{{ __("Amount to DSP") }}:</strong> {{ get_amount($escrows->escrowDetails->delivery_get ?? 0, $escrows->escrow_currency) }}
                        @endif
                    </div>
                    
                    @include('admin.components.form.textarea',[
                        'label'         => 'Admin Notes (Optional)',
                        'name'          => 'admin_notes',
                        'value'         => old('admin_notes')
                    ])
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn--danger" data-bs-dismiss="modal">{{ __("Cancel") }}</button>
                <button type="submit" class="btn btn--success">{{ __("Release to Merchant") }}</button>
            </div>
            </form>
        </div>
    </div>
</div>

{{-- Release to Buyer Modal --}}
<div class="modal fade" id="releaseToBuyerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header p-3 bg-info text-white">
                <h5 class="modal-title text-white">{{ __("Release to Buyer") }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form class="modal-form" action="{{ setRoute('admin.escrow.release.to.buyer') }}" method="POST">
                    @csrf
                    @method("PUT")
                    <input type="hidden" name="id" value="{{ @$escrows->id }}">
                    
                    <div class="alert alert-warning">
                        <strong>{{ __("Refund to Buyer") }}:</strong> {{ get_amount($escrows->escrowDetails->buyer_pay ?? 0, $escrows->escrow_currency) }}
                        @if($escrows->delivery_id)
                        <br><small class="text-muted">{{ __("Note: DSP will NOT be paid in this scenario.") }}</small>
                        @endif
                    </div>
                    
                    @include('admin.components.form.textarea',[
                        'label'         => 'Reason for Release to Buyer*',
                        'name'          => 'release_reason',
                        'value'         => old('release_reason')
                    ])
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn--danger" data-bs-dismiss="modal">{{ __("Cancel") }}</button>
                <button type="submit" class="btn btn--info">{{ __("Release to Buyer") }}</button>
            </div>
            </form>
        </div>
    </div>
</div>

{{-- Split Payment Modal --}}
<div class="modal fade" id="splitPaymentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header p-3 bg-warning">
                <h5 class="modal-title">{{ __("Split Payment") }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form class="modal-form" action="{{ setRoute('admin.escrow.split.payment') }}" method="POST">
                    @csrf
                    @method("PUT")
                    <input type="hidden" name="id" value="{{ @$escrows->id }}">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>{{ __("Amount to Merchant") }} ({{ $escrows->escrow_currency }})</label>
                                <input type="number" name="merchant_amount" class="form-control" 
                                       value="{{ $escrows->escrowDetails->seller_get ?? 0 }}" step="0.01" min="0">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>{{ __("Amount to Buyer") }} ({{ $escrows->escrow_currency }})</label>
                                <input type="number" name="buyer_amount" class="form-control" 
                                       value="0" step="0.01" min="0">
                            </div>
                        </div>
                        @if($escrows->delivery_id)
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>{{ __("Amount to DSP") }} ({{ $escrows->escrow_currency }})</label>
                                <input type="number" name="dsp_amount" class="form-control" 
                                       value="{{ $escrows->escrowDetails->delivery_get ?? 0 }}" step="0.01" min="0">
                            </div>
                        </div>
                        @endif
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <strong>{{ __("Total Available") }}:</strong> {{ get_amount($escrows->escrowDetails->buyer_pay ?? 0, $escrows->escrow_currency) }}
                    </div>
                    
                    @include('admin.components.form.textarea',[
                        'label'         => 'Reason for Split*',
                        'name'          => 'split_reason',
                        'value'         => old('split_reason')
                    ])
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn--danger" data-bs-dismiss="modal">{{ __("Cancel") }}</button>
                <button type="submit" class="btn btn--warning">{{ __("Apply Split") }}</button>
            </div>
            </form>
        </div>
    </div>
</div>

@endsection

@push('script')
<script>
    $(document).ready(function(){
        @if($errors->any())
        var modal = $('#rejectModal');
        modal.modal('show');
        @endif
    });
    
    (function ($) {
        "use strict";
        
        // Payment approval modals
        $('.approvedBtn').on('click', function () {
            $('#approvedModal').modal('show');
        });
        $('.rejectBtn').on('click', function () {
            $('#rejectModal').modal('show');
        });
        
        // Release modals
        $('.releaseToMerchantBtn').on('click', function () {
            $('#releaseToMerchantModal').modal('show');
        });
        $('.releaseToBuyerBtn').on('click', function () {
            $('#releaseToBuyerModal').modal('show');
        });
        $('.splitPaymentBtn').on('click', function () {
            $('#splitPaymentModal').modal('show');
        });
        
    })(jQuery);
</script>
@endpush
