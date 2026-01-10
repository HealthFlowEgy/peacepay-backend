@extends('admin.layouts.master')

@push('css')
<style>
    .status-badge {
        padding: 4px 10px;
        border-radius: 15px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
    }
    .status-pending { background: #fef3cd; color: #856404; }
    .status-active { background: #d1ecf1; color: #0c5460; }
    .status-completed { background: #d4edda; color: #155724; }
    .status-cancelled { background: #f8d7da; color: #721c24; }
    .status-disputed { background: #fff3cd; color: #856404; }
    
    .filter-tabs {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }
    .filter-tab {
        padding: 8px 16px;
        border-radius: 20px;
        border: 1px solid #e9ecef;
        background: #fff;
        cursor: pointer;
        transition: all 0.2s;
        font-size: 13px;
        text-decoration: none;
        color: #333;
    }
    .filter-tab:hover {
        border-color: #007bff;
        color: #007bff;
    }
    .filter-tab.active {
        background: #007bff;
        color: #fff;
        border-color: #007bff;
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
        ],
        'active' => __('PeaceLinks Management'),
    ])
@endsection

@section('content')
    <div class="table-area">
        <div class="table-wrapper">
            <div class="table-header">
                <h5 class="title">{{ __('PeaceLinks') }}</h5>
                <div class="table-btn-area"> 
                    @include('admin.components.search-input',[
                        'name'  => 'escrow_search',
                    ]) 
                </div>
            </div>
            
            {{-- Filter Tabs --}}
            <div class="filter-tabs">
                <a href="{{ setRoute('admin.escrow.index') }}" class="filter-tab {{ !request('status') ? 'active' : '' }}">
                    {{ __('All') }}
                </a>
                <a href="{{ setRoute('admin.escrow.index', ['status' => 'pending']) }}" class="filter-tab {{ request('status') == 'pending' ? 'active' : '' }}">
                    {{ __('Pending') }}
                </a>
                <a href="{{ setRoute('admin.escrow.index', ['status' => 'active']) }}" class="filter-tab {{ request('status') == 'active' ? 'active' : '' }}">
                    {{ __('Active') }}
                </a>
                <a href="{{ setRoute('admin.escrow.index', ['status' => 'in_transit']) }}" class="filter-tab {{ request('status') == 'in_transit' ? 'active' : '' }}">
                    {{ __('In Transit') }}
                </a>
                <a href="{{ setRoute('admin.escrow.index', ['status' => 'disputed']) }}" class="filter-tab {{ request('status') == 'disputed' ? 'active' : '' }}">
                    {{ __('Disputed') }}
                </a>
                <a href="{{ setRoute('admin.escrow.index', ['status' => 'completed']) }}" class="filter-tab {{ request('status') == 'completed' ? 'active' : '' }}">
                    {{ __('Completed') }}
                </a>
                <a href="{{ setRoute('admin.escrow.index', ['status' => 'cancelled']) }}" class="filter-tab {{ request('status') == 'cancelled' ? 'active' : '' }}">
                    {{ __('Cancelled') }}
                </a>
            </div>
            
            <div class="table-responsive">
                @include('admin.components.data-table.escrow-log',[
                    'data'  => $escrows
                ])
            </div>
            {{ get_paginate($escrows) }}
        </div>
    </div>
@endsection

@push('script')
    <script>
        itemSearch($("input[name=escrow_search]"),$(".transaction-search-table"),"{{ setRoute('admin.escrow.search') }}",1);
    </script>
@endpush
