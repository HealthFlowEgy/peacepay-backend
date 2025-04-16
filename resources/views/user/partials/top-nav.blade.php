<nav class="navbar-wrapper">
    <div class="dashboard-title-part">
        <div class="left">
            <div class="icon">
                <button class="sidebar-menu-bar">
                    <i class="fas fa-exchange-alt"></i>
                </button>
            </div>
            @yield('breadcrumb')
        </div>
        <div class="right">
            <div class="toggle-container width-auto">
                <a class="btn btn--base" href="{{route('user.profile.pin')}}">
                    @if(auth()->user()->pin_code)
                        {{__('Update PIN Code')}}
                    @else
                        {{__('Create PIN Code')}}
                    @endif
                </a>
            </div>

            <div class="toggle-container width-auto">
                <a class="btn btn--base" href="{{route('user.add.money.index')}}">
                    {{__('Add Money')}}
                </a>
            </div>

            <div class="toggle-container width-auto">
                <a class="btn btn--base" href="{{route('user.money.out.index')}}">
                    {{__('Money Out')}}
                </a>
            </div>

            @if(auth()->user()->type == 'seller')
                <div class="toggle-container width-auto">
                    <a class="btn btn--base" href="{{route('user.my-escrow.add')}}">
                        {{__('Create Escrow')}}
                    </a>
                </div>
            @endif

            <div class="toggle-container">
                <div class="switch-toggles user_type_show {{ auth()->user()->type == 'buyer' ? 'active' : ''; }}" data-deactive="deactive">
                    <input type="hidden" class="user_type_data" value="1">
                    <span class="switch user_type" data-value="1">{{ __("Buyer") }}</span>
                    <span class="switch user_type" data-value="0">{{ __("Seller") }}</span>
                </div>
            </div>
            <div class="header-notification-wrapper">
                <button class="notification-icon notificationAction">
                    <i class="las la-bell"></i>
                    @if (count(App\Models\UserNotification::where(['user_id'=>auth()->user()->id,'seen'=>0])->get()) > 0)
                    <span class="bling-area">
                        <span class="bling"></span>
                    </span>
                    @endif 
                </button>
                <div class="notification-wrapper">
                    <div class="notification-header">
                        <h5 class="title">{{ __("Notification") }}</h5>
                    </div>
                    <ul class="notification-list"> 
                        @foreach (get_user_notifications(5) ?? [] as $item)
                        <li>
                            {{-- <div class="thumb">
                                <img src="{{ auth()->user()->userImage }}" alt="user" />
                            </div> --}}
                            <div class="content">
                                <div class="title-area">
                                    <h5 class="title">{{ __($item->message->title) }}</h5>
                                    <span class="time">{{ $item->created_at->diffForHumans() }}</span>
                                </div>
                                <span class="sub-title">{{ @$item->message->message ?? "" }}</span>
                            </div>
                        </li>
                        @endforeach
                    </ul>
                </div>
            </div>
            <div class="header-language">
                @php
                    $session_lan = session('local')??get_default_language_code();
                @endphp
                <select name="lang_switch" class="form--control language-select nice-select" id="language-select">
                    @foreach($__languages as $item)
                        <option value="{{$item->code}}" @if($session_lan == $item->code) selected  @endif>{{ __($item->name) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="header-user-wrapper">
                <div class="header-user-thumb">
                    <a href="{{ setRoute('user.profile.index') }}"><img src="{{ auth()->user()->userImage }}" alt="user"></a>
                </div>
            </div>
        </div>
    </div>
</nav>
@push('script')
    <script>
        $('.notificationAction').click(function(){
            var URL = "{{ setRoute('user.notifications.seen.update') }}";
            var formData = {
                '_token': laravelCsrf(),
            };
            $.post(URL,formData,function(response) {
            }).done(function(response){
                $('.bling-area').addClass('d-none') 
            }).fail(function(response) {
                
            });
        });

        $(document).ready(function () {
            $(".language-select").change(function(){ 
                var submitForm = `<form action="{{ setRoute('languages.switch') }}" id="local_submit" method="POST"> @csrf <input type="hidden" name="target" value="${$(this).val()}" ></form>`;
                $("body").append(submitForm);
                $("#local_submit").submit();
            });
        });
</script>
@endpush