@extends('user.layouts.master') 
@section('breadcrumb')
    @include('user.components.breadcrumb',['breadcrumbs' => [
        [
            'name'  => __("Dashboard"),
            'url'   => setRoute("user.dashboard"),
        ]
    ], 'active' => __($page_title)])
@endsection

@section('content')
        <div class="body-wrapper">
            <div class="row mt-20 mb-20-none">
                <div class="col-xl-6 col-lg-6 mb-20">
                    <div class="custom-card mt-10">
                        <div class="dashboard-header-wrapper">
                            <h4 class="title">{{ __("PIN Code") }}</h4>
                        </div>
                        <div class="card-body profile-body-wrapper">
                            <form class="card-form" method="POST" action="{{ setRoute('user.profile.pin') }}" enctype="multipart/form-data">
                                @csrf
                                @method("PUT")
                                <div class="profile-form-area text-center">

                                    <div class="row">
                                        @if(auth()->user()->pin_code)
                                            <div class="col-lg-12 form-group">
                                                @include('admin.components.form.input',[
                                                    'label'         => __("Current PIN Code")."<span>*</span>",
                                                    'name'          => "current_pin_code",
                                                    'value'         => old('current_pin_code'),
                                                    'required'      => true
                                                ])
                                            </div>
                                        @endif
                                        <div class="col-lg-12 form-group">
                                            @include('admin.components.form.input',[
                                                'label'         => __("PIN Code")."<span>*</span>",
                                                'name'          => "pin_code",
                                                'value'         => old('pin_code'),
                                                'required'      => true
                                            ])
                                        </div>
                                        @if(auth()->user()->pin_code)
                                            <div class="col-lg-12 form-group">
                                                @include('admin.components.form.input',[
                                                    'label'         => __("Confirm PIN Code")."<span>*</span>",
                                                    'name'          => "pin_code_confirmation",
                                                    'value'         => old('pin_code_confirmation'),
                                                    'required'      => true
                                                ])
                                            </div>
                                        @endif
                                    </div>

                                    <div class="col-xl-12 col-lg-12">
                                        <button type="submit" class="btn--base w-100">{{ __("Update") }}</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>     
@endsection


@push('script')
        <!--~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
            Start Modal
        ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~-->
        <div class="modal fade" id="deleteModal" tabindex="1" aria-labelledby="deleteModalLabel" aria-hidden="false">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header" id="deleteModalLabel">
                        <h5 class="modal-title">{{ __("Are you sure to delete your account") }}?</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>{{ __("If you do not think you will use") }} “{{ $basic_settings->site_name }}” {{ __("again and like your account deleted, we can take card of this for you. Keep in mind you will not be able to reactivate your account or retrieve any of the content or information you have added. If you would still like your account deleted, click “Delete Account”") }}.?</p>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn--base" data-bs-dismiss="modal" aria-label="Close">{{ __("Cancel") }}</button>
                        <form action="{{ setRoute('user.delete.account') }}" method="POST">
                            @csrf
                            <button type="submit" class="btn--base bg--danger">{{ __("Delete Account") }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <!--~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
            End Modal
        ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~-->
    <script>
        getAllCountries("{{ setRoute('global.countries') }}");
        $(document).ready(function(){
            $("select[name=country]").change(function(){
                var phoneCode = $("select[name=country] :selected").attr("data-mobile-code");
                placePhoneCode(phoneCode);
            });

            countrySelect(".country-select",$(".country-select").siblings(".select2"));
            stateSelect(".state-select",$(".state-select").siblings(".select2"));
        }); 
    </script>
@endpush