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
    ], 'active' => __("Incentive Balance Settings")])
@endsection

@section('content')
    <div class="custom-card">
        <div class="card-header">
            <h6 class="title">{{ __("New User Incentive Balance") }}</h6>
        </div>
        <div class="card-body">
            <form action="{{ setRoute('admin.trx.settings.incentive.balance.update') }}" method="POST">
                @csrf
                @method('PUT')
                <div class="row mb-10-none">
                    <div class="col-xl-4 col-lg-4 col-md-6 col-sm-12 form-group">
                        @include('admin.components.form.input',[
                            'label'         => 'Seller Incentive Balance*',
                            'name'          => 'incentive_balance_seller',
                            'value'         => old('incentive_balance_seller', $basic_settings->incentive_balance_seller ?? 0),
                            'placeholder'   => 'Enter Amount',
                            'attribute'     => 'step="0.01"',
                        ])
                    </div>
                    <div class="col-xl-4 col-lg-4 col-md-6 col-sm-12 form-group">
                        @include('admin.components.form.input',[
                            'label'         => 'Buyer Incentive Balance*',
                            'name'          => 'incentive_balance_buyer',
                            'value'         => old('incentive_balance_buyer', $basic_settings->incentive_balance_buyer ?? 0),
                            'placeholder'   => 'Enter Amount',
                            'attribute'     => 'step="0.01"',
                        ])
                    </div>
                    <div class="col-xl-4 col-lg-4 col-md-6 col-sm-12 form-group">
                        @include('admin.components.form.input',[
                            'label'         => 'Delivery Incentive Balance*',
                            'name'          => 'incentive_balance_delivery',
                            'value'         => old('incentive_balance_delivery', $basic_settings->incentive_balance_delivery ?? 0),
                            'placeholder'   => 'Enter Amount',
                            'attribute'     => 'step="0.01"',
                        ])
                    </div>
                    <div class="col-xl-12 col-lg-12 form-group">
                        <div class="note-area">
                            <div class="note-title">
                                <span class="text--base">{{ __("Note") }}:</span>
                            </div>
                            <p>{{ __("When a new user registers with a specific type (Seller, Buyer, or Delivery), they will automatically receive the corresponding incentive balance in their wallet.") }}</p>
                        </div>
                    </div>
                    <div class="col-xl-12 col-lg-12 form-group">
                        @include('admin.components.button.form-btn',[
                            'text'          => 'Update',
                            'permission'    => 'admin.trx.settings.incentive.balance.update',
                            'class'         => 'btn-loading w-100 btn-primary',
                        ])
                    </div>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('script')

@endpush
