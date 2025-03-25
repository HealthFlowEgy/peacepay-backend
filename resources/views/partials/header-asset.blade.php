<!-- favicon -->
<link
    rel="shortcut icon"
    href="{{ get_fav($basic_settings) }}"
    type="image/x-icon"
/>
<!-- fontawesome css link -->
<link
    rel="stylesheet"
    href="{{ asset('public/frontend/css/fontawesome-all.css') }}"
/>
<!-- bootstrap css link -->
<link
    rel="stylesheet"
    href="{{ asset('public/frontend/css/bootstrap.css') }}"
/>
<!-- swipper css link -->
<link rel="stylesheet" href="{{ asset('public/frontend/css/swiper.css') }}" />
<!-- lightcase css links -->
<link
    rel="stylesheet"
    href="{{ asset('public/frontend/css/lightcase.css') }}"
/>
<!-- odometer css link -->
<link rel="stylesheet" href="{{ asset('public/frontend/css/odometer.css') }}" />
<!-- line-awesome-icon css -->
<link
    rel="stylesheet"
    href="{{ asset('public/frontend/css/line-awesome.css') }}"
/>
<!-- animate.css -->
<link rel="stylesheet" href="{{ asset('public/frontend/css/animate.css') }}" />
<!-- nice select css -->
<link
    rel="stylesheet"
    href="{{ asset('public/frontend/css/nice-select.css') }}"
/>
<!-- Select 2 CSS -->
<link rel="stylesheet" href="{{ asset('public/backend/css/select2.css') }}" />
<link
    rel="stylesheet"
    href="{{ asset('public/backend/library/popup/magnific-popup.css') }}"
/>
<!-- file holder css -->
<link
    rel="stylesheet"
    href="https://cdn.appdevs.net/fileholder/v1.0/css/fileholder-style.css"
    type="text/css"
/>
<!-- main style css link -->
<link rel="stylesheet" href="{{ asset('public/frontend/css/style.css') }}" />

<!-- @if(auth()->check() && auth()->user()->type == 'seller')
        <link rel="stylesheet" href="{{ asset('public/frontend/css/style-seller.css') }}">
        @endif -->

@php 
    $color = @$basic_settings->base_color ?? '#000000';
    
    if (auth()->user()->type === 'seller') {
        $color = '#4CAF50'; // Green color for sellers
    }
@endphp

<style>
    :root {
        --primary-color: {{$color}};
    }
</style>

<script>
//     function replaceColors() {
//         // Select all elements in the document
//         const allElements = document.getElementsByTagName("*");

//         for (let element of allElements) {
//             // Check computed styles
//             const computedStyle = window.getComputedStyle(element);

//             if (
//                 computedStyle.backgroundColor === "rgb(15, 16, 30)" ||
//                 computedStyle.backgroundColor === "#0f101e"
//             ) {
//                 element.style.backgroundColor = "#ffffff";
//             }

//             if (
//             computedStyle.color === "rgb(255, 255, 255)" || 
//             computedStyle.color === "#ffffff"
//         ) {
//             element.style.color = "black";
//         }
//         }
//     }

//     // Run the color replacement
//     replaceColors();

//     // Optional: Run again after a short delay in case of dynamic content
//     setTimeout(replaceColors, 1000);
</script>
