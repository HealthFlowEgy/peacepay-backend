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
                <div class="table-responsive">
                    <table class="custom-table">
                        <tr>
                            <th>No</th>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Collected From</th>
                            <th width="280px">Action</th>
                        </tr>
                        @foreach ($policies as $policy)
                        <tr>
                            <td>{{ ++$loop->index }}</td>
                            <td>{{ $policy->name }}</td>
                            <td>{{ Str::limit($policy->description, 50) }}</td>
                            <td>{{ ucfirst($policy->collected_from) }}</td>
                            <td>
                                <form action="{{ route('user.policies.destroy',$policy->id) }}" method="POST">
                                    <a class="btn btn--info" href="{{ route('user.policies.show',$policy->id) }}">Show</a>
                                    <a class="btn btn--primary" href="{{ route('user.policies.edit',$policy->id) }}">Edit</a>
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn--danger" onclick="return confirm('Are you sure you want to delete this policy?')">Delete</button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                    </table>
                    {{ get_paginate($policies) }}
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script')
    <script>
    </script>
@endpush