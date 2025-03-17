@extends('user.layouts.master') @section('breadcrumb')
@include('user.components.breadcrumb',['breadcrumbs' => [ [ 'name' =>
__("Dashboard"), 'url' => setRoute("user.dashboard"), ] ], 'active' =>
__("Create New Policy")]) @endsection @section('content')
<div class="body-wrapper">
    <div class="row mt-20 mb-20-none">
        <div class="col-xl-12 col-lg-12 mb-20">
            <div class="custom-card mt-10">
                <div class="dashboard-header-wrapper">
                    <h4 class="title">{{ __("Create New Policy") }}</h4>
                </div>
                <div class="card-body">
                    <form
                        action="{{ setRoute('user.policies.update', $policy->id) }}"
                        class="card-form policy-form"
                        method="POST"
                        enctype="multipart/form-data"
                    >
                        @csrf @method('PUT')

                        <div class="row mb-3">
                            <div class="col-xs-12 col-sm-12 col-md-12">
                                <div class="form-group">
                                    <label>Name:</label>
                                    <input
                                        type="text"
                                        name="name"
                                        value="{{ $policy->name }}"
                                        class="form--control"
                                        placeholder="Name"
                                    />
                                </div>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-xs-12 col-sm-12 col-md-12">
                                <div class="form-group">
                                    <label>Description:</label>
                                    <textarea
                                        class="form--control"
                                        style="height: 150px"
                                        name="description"
                                        placeholder="Description"
                                        >{{ $policy->description }}</textarea
                                    >
                                </div>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-xs-12 col-sm-12 col-md-12">
                                <div class="form-group">
                                    <label>Collected From:</label>
                                    <select
                                        name="collected_from"
                                        class="form--control"
                                    >
                                        <option value="buyer" {{ $policy->
                                            collected_from == 'buyer' ?
                                            'selected' : '' }}>Buyer
                                        </option>
                                        <option value="seller" {{ $policy->
                                            collected_from == 'seller' ?
                                            'selected' : '' }}>Seller
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div
                                class="col-xs-12 col-sm-12 col-md-12 text-center"
                            >
                                <button type="submit" class="btn btn-primary">
                                    Submit
                                </button>
                            </div>
                        </div>

                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection @push('script')
<script></script>
@endpush
