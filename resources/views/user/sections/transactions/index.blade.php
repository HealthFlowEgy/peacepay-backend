@extends('user.layouts.master') 
@section('breadcrumb')
    @include('user.components.breadcrumb',['breadcrumbs' => [
        [
            'name'  => __("Dashboard"),
            'url'   => setRoute("user.dashboard"),
        ]
    ], 'active' => __("Transactions")])
@endsection

@section('content') 
<style>
    .modal-backdrop{
        top: auto !important;
    }
</style>
    <div class="body-wrapper">
        <div class="dashboard-list-area mt-20">
            <div class="dashboard-header-wrapper">
                <h4 class="title">{{ $page_title ?? "" }}</h4>
                <div class="preview-list-right">
                    <button type="button" class="btn--base" data-bs-toggle="modal" data-bs-target="#walletModal">
                        {{ __('Transfer to Wallet') }}
                    </button>
                </div>

                <div class="modal" id="walletModal" tabindex="-1" aria-labelledby="walletModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="walletModalLabel">Modal title</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form action="{{ route('user.transactions.transfer') }}" method="POST">
                                <div class="modal-body">
                                    @csrf
                                    <div class="mb-3">
                                        <label for="to" class="form-label">{{ __('Wallet Number')}}</label>
                                        <input type="number" class="form--control" id="to" name="to" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="amount" class="form-label">Amount</label>
                                        <input type="number" class="form--control" id="amount" name="amount" required>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                    <button type="submit" class="btn btn-primary">Save changes</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            @include('user.components.wallets.transation-log', compact('transactions'))
        </div>
    </div>

@endsection 