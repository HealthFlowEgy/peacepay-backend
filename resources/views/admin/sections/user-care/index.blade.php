@extends('admin.layouts.master')

@push('css')
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
        'active' => __('User Care'),
    ])
@endsection

@section('content')
    <div class="table-area">
        <div class="table-wrapper">
            <div class="table-header">
                <h5 class="title">{{ __("All Users") }}</h5>
                <div class="table-btn-area">
                    @include('admin.components.search-input',[
                        'name'  => 'user_search',
                    ])
                </div>
            </div>
            <div class="table-responsive">
                @include('admin.components.data-table.user-table',compact('users'))
            </div>
        </div>
        {{ get_paginate($users) }}
    </div>
@endsection

@push('script')
    <script>
        // Custom search with source_route parameter
        var timeOut;
        $("input[name=user_search]").bind("keyup", function(){
            clearTimeout(timeOut);
            var inputElement = $(this);
            timeOut = setTimeout(function() {
                executeCustomItemSearch(inputElement, $(".user-search-table"), "{{ setRoute('admin.users.search') }}", "{{ Route::currentRouteName() }}", 3);
            }, 500);
        });

        function executeCustomItemSearch(inputElement, tableElement, URL, sourceRoute, minTextLength) {
            $(tableElement).parent().find(".search-result-table").remove();
            var searchText = inputElement.val();
            if(searchText.length > minTextLength) {
                $(tableElement).addClass("d-none");
                makeCustomSearchItemXmlRequest(searchText, tableElement, URL, sourceRoute);
            } else {
                $(tableElement).removeClass("d-none");
            }
        }

        function makeCustomSearchItemXmlRequest(searchText, tableElement, URL, sourceRoute) {
            var data = {
                _token: "{{ csrf_token() }}",
                text: searchText,
                source_route: sourceRoute
            };
            $.post(URL, data, function(response) {
                // response
            }).done(function(response){
                if(response == "") {
                    throwMessage('error',["No data found!"]);
                }
                if($(tableElement).siblings(".search-result-table").length > 0) {
                    $(tableElement).parent().find(".search-result-table").html(response);
                } else {
                    $(tableElement).after(`<div class="search-result-table"></div>`);
                    $(tableElement).parent().find(".search-result-table").html(response);
                }
            }).fail(function(response) {
                throwMessage('error',["Something went wrong! Please try again."]);
            });
        }
    </script>
@endpush
