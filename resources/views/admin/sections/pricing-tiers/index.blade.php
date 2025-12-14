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
    ], 'active' => __("Pricing Tiers")])
@endsection

@section('content')
    <div class="table-area">
        <div class="table-wrapper">
            <div class="table-header">
                <h5 class="title">{{ __("Pricing Tiers") }}</h5>
                <div class="table-btn-area">
                    @include('admin.components.link.add-default',[
                        'text'          => __("Add Tier"),
                        'href'          => "#pricing-tier-add",
                        'class'         => "modal-btn",
                    ])
                </div>
            </div>
            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>{{ __("Name") }}</th>
                            <th>{{ __("Delivery Fees") }}</th>
                            <th>{{ __("Merchant Fees") }}</th>
                            <th>{{ __("Cash Out Fees") }}</th>
                            <th>{{ __("Users") }}</th>
                            <th>{{ __("Status") }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($pricing_tiers as $item)
                            <tr data-item="{{ json_encode($item) }}">
                                <td>{{ $item->name }}</td>
                                <td>{{ get_amount($item->delivery_percent_charge) }}% + {{ get_amount($item->delivery_fixed_charge) }}</td>
                                <td>{{ get_amount($item->merchant_percent_charge) }}% + {{ get_amount($item->merchant_fixed_charge) }}</td>
                                <td>{{ get_amount($item->cash_out_percent_charge) }}% + {{ get_amount($item->cash_out_fixed_charge) }}</td>
                                <td>{{ $item->users()->count() }}</td>
                                <td>
                                    @include('admin.components.form.switcher',[
                                        'name'          => 'status',
                                        'value'         => $item->status,
                                        'options'       => [__("Enable") => 1, __("Disable") => 0],
                                        'onload'        => true,
                                        'data_target'   => $item->id,
                                    ])
                                </td>
                                <td>
                                    @include('admin.components.link.edit-default',[
                                        'href'          => "javascript:void(0)",
                                        'class'         => "edit-modal-button",
                                        'permission'    => "admin.pricing.tiers.update",
                                    ])
                                    @include('admin.components.link.delete-default',[
                                        'href'          => "javascript:void(0)",
                                        'class'         => "delete-modal-button",
                                        'permission'    => "admin.pricing.tiers.delete",
                                    ])
                                </td>
                            </tr>
                        @empty
                            @include('admin.components.alerts.empty',['colspan' => 7])
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        {{ get_paginate($pricing_tiers) }}
    </div>

    {{-- Add Modal --}}
    <div id="pricing-tier-add" class="mfp-hide large">
        <div class="modal-data">
            <div class="modal-header px-0">
                <h5 class="modal-title">{{ __("Add Pricing Tier") }}</h5>
            </div>
            <div class="modal-form-data">
                <form class="modal-form" method="POST" action="{{ setRoute('admin.pricing.tiers.store') }}">
                    @csrf
                    <div class="row mb-10-none">
                        <div class="col-xl-12 col-lg-12 form-group">
                            @include('admin.components.form.input',[
                                'label'         => __("Tier Name").'*',
                                'name'          => 'name',
                                'value'         => old('name')
                            ])
                        </div>
                        <div class="col-xl-12 col-lg-12 form-group">
                            @include('admin.components.form.textarea',[
                                'label'         => __("Description"),
                                'name'          => 'description',
                                'value'         => old('description')
                            ])
                        </div>

                        <div class="col-xl-12"><h6 class="mb-3">{{ __("Delivery Fees") }}</h6></div>
                        <div class="col-xl-6 col-lg-6 form-group">
                            @include('admin.components.form.input',[
                                'label'         => __("Fixed Charge").'*',
                                'name'          => 'delivery_fixed_charge',
                                'value'         => old('delivery_fixed_charge', 0),
                                'attribute'     => 'step="0.01"',
                            ])
                        </div>
                        <div class="col-xl-6 col-lg-6 form-group">
                            @include('admin.components.form.input',[
                                'label'         => __("Percent Charge (%)") .'*',
                                'name'          => 'delivery_percent_charge',
                                'value'         => old('delivery_percent_charge', 0),
                                'attribute'     => 'step="0.01"',
                            ])
                        </div>

                        <div class="col-xl-12"><h6 class="mb-3">{{ __("Merchant Fees") }}</h6></div>
                        <div class="col-xl-6 col-lg-6 form-group">
                            @include('admin.components.form.input',[
                                'label'         => __("Fixed Charge").'*',
                                'name'          => 'merchant_fixed_charge',
                                'value'         => old('merchant_fixed_charge', 0),
                                'attribute'     => 'step="0.01"',
                            ])
                        </div>
                        <div class="col-xl-6 col-lg-6 form-group">
                            @include('admin.components.form.input',[
                                'label'         => __("Percent Charge (%)") .'*',
                                'name'          => 'merchant_percent_charge',
                                'value'         => old('merchant_percent_charge', 0),
                                'attribute'     => 'step="0.01"',
                            ])
                        </div>

                        <div class="col-xl-12"><h6 class="mb-3">{{ __("Cash Out Fees") }}</h6></div>
                        <div class="col-xl-6 col-lg-6 form-group">
                            @include('admin.components.form.input',[
                                'label'         => __("Fixed Charge").'*',
                                'name'          => 'cash_out_fixed_charge',
                                'value'         => old('cash_out_fixed_charge', 0),
                                'attribute'     => 'step="0.01"',
                            ])
                        </div>
                        <div class="col-xl-6 col-lg-6 form-group">
                            @include('admin.components.form.input',[
                                'label'         => __("Percent Charge (%)") .'*',
                                'name'          => 'cash_out_percent_charge',
                                'value'         => old('cash_out_percent_charge', 0),
                                'attribute'     => 'step="0.01"',
                            ])
                        </div>

                        <div class="col-xl-12 col-lg-12 form-group">
                            @include('admin.components.form.switcher',[
                                'label'         => __('Status'),
                                'name'          => 'status',
                                'value'         => old('status', 1),
                                'options'       => [__("Enable") => 1, __("Disable") => 0],
                            ])
                        </div>

                        <div class="col-xl-12 col-lg-12 form-group d-flex align-items-center justify-content-between mt-4">
                            <button type="button" class="btn btn--danger modal-close">{{ __("Cancel") }}</button>
                            <button type="submit" class="btn btn--base">{{ __("Add") }}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Edit Modal --}}
    <div id="pricing-tier-edit" class="mfp-hide large">
        <div class="modal-data">
            <div class="modal-header px-0">
                <h5 class="modal-title">{{ __("Edit Pricing Tier") }}</h5>
            </div>
            <div class="modal-form-data">
                <form class="modal-form" method="POST" action="{{ setRoute('admin.pricing.tiers.update') }}">
                    @csrf
                    @method("PUT")
                    <input type="hidden" name="target" value="">
                    <div class="row mb-10-none">
                        <div class="col-xl-12 col-lg-12 form-group">
                            @include('admin.components.form.input',[
                                'label'         => __("Tier Name").'*',
                                'name'          => 'name',
                                'value'         => old('name')
                            ])
                        </div>
                        <div class="col-xl-12 col-lg-12 form-group">
                            @include('admin.components.form.textarea',[
                                'label'         => __("Description"),
                                'name'          => 'description',
                                'value'         => old('description')
                            ])
                        </div>

                        <div class="col-xl-12"><h6 class="mb-3">{{ __("Delivery Fees") }}</h6></div>
                        <div class="col-xl-6 col-lg-6 form-group">
                            @include('admin.components.form.input',[
                                'label'         => __("Fixed Charge").'*',
                                'name'          => 'delivery_fixed_charge',
                                'value'         => old('delivery_fixed_charge'),
                                'attribute'     => 'step="0.01"',
                            ])
                        </div>
                        <div class="col-xl-6 col-lg-6 form-group">
                            @include('admin.components.form.input',[
                                'label'         => __("Percent Charge (%)") .'*',
                                'name'          => 'delivery_percent_charge',
                                'value'         => old('delivery_percent_charge'),
                                'attribute'     => 'step="0.01"',
                            ])
                        </div>

                        <div class="col-xl-12"><h6 class="mb-3">{{ __("Merchant Fees") }}</h6></div>
                        <div class="col-xl-6 col-lg-6 form-group">
                            @include('admin.components.form.input',[
                                'label'         => __("Fixed Charge").'*',
                                'name'          => 'merchant_fixed_charge',
                                'value'         => old('merchant_fixed_charge'),
                                'attribute'     => 'step="0.01"',
                            ])
                        </div>
                        <div class="col-xl-6 col-lg-6 form-group">
                            @include('admin.components.form.input',[
                                'label'         => __("Percent Charge (%)") .'*',
                                'name'          => 'merchant_percent_charge',
                                'value'         => old('merchant_percent_charge'),
                                'attribute'     => 'step="0.01"',
                            ])
                        </div>

                        <div class="col-xl-12"><h6 class="mb-3">{{ __("Cash Out Fees") }}</h6></div>
                        <div class="col-xl-6 col-lg-6 form-group">
                            @include('admin.components.form.input',[
                                'label'         => __("Fixed Charge").'*',
                                'name'          => 'cash_out_fixed_charge',
                                'value'         => old('cash_out_fixed_charge'),
                                'attribute'     => 'step="0.01"',
                            ])
                        </div>
                        <div class="col-xl-6 col-lg-6 form-group">
                            @include('admin.components.form.input',[
                                'label'         => __("Percent Charge (%)") .'*',
                                'name'          => 'cash_out_percent_charge',
                                'value'         => old('cash_out_percent_charge'),
                                'attribute'     => 'step="0.01"',
                            ])
                        </div>

                        <div class="col-xl-12 col-lg-12 form-group">
                            @include('admin.components.form.switcher',[
                                'label'         => __('Status'),
                                'name'          => 'status',
                                'value'         => old('status'),
                                'options'       => [__("Enable") => 1, __("Disable") => 0],
                            ])
                        </div>

                        <div class="col-xl-12 col-lg-12 form-group d-flex align-items-center justify-content-between mt-4">
                            <button type="button" class="btn btn--danger modal-close">{{ __("Cancel") }}</button>
                            <button type="submit" class="btn btn--base">{{ __("Update") }}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

@endsection

@push('script')
    <script>
        openModalWhenError("pricing_tier_add","#pricing-tier-add");
        openModalWhenError("pricing_tier_edit","#pricing-tier-edit");

        $(".edit-modal-button").click(function(){
            var oldData = JSON.parse($(this).parents("tr").attr("data-item"));
            var editModal = $("#pricing-tier-edit");

            editModal.find("form").first().find("input[name=target]").val(oldData.id);
            editModal.find("input[name=name]").val(oldData.name);
            editModal.find("textarea[name=description]").val(oldData.description);
            editModal.find("input[name=delivery_fixed_charge]").val(oldData.delivery_fixed_charge);
            editModal.find("input[name=delivery_percent_charge]").val(oldData.delivery_percent_charge);
            editModal.find("input[name=merchant_fixed_charge]").val(oldData.merchant_fixed_charge);
            editModal.find("input[name=merchant_percent_charge]").val(oldData.merchant_percent_charge);
            editModal.find("input[name=cash_out_fixed_charge]").val(oldData.cash_out_fixed_charge);
            editModal.find("input[name=cash_out_percent_charge]").val(oldData.cash_out_percent_charge);
            editModal.find("input[name=status]").val(oldData.status);

            refreshSwitchers("#pricing-tier-edit");
            openModalBySelector("#pricing-tier-edit");

        });

        $(".delete-modal-button").click(function(){
            var oldData = JSON.parse($(this).parents("tr").attr("data-item"));
            var actionRoute =  "{{ setRoute('admin.pricing.tiers.delete') }}";
            var target = oldData.id;

            var message     = `Are you sure to <strong>delete</strong> this tier?`;

            openDeleteModal(actionRoute,target,message);
        });

        $(document).ready(function(){
            // Switcher
            switcherAjax("{{ setRoute('admin.pricing.tiers.status.update') }}");
        })
    </script>
@endpush
