<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Opening in App...</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .container {
            text-align: center;
            padding: 2rem;
            max-width: 500px;
        }
        .spinner {
            border: 4px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top: 4px solid white;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 2rem;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        h1 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }
        p {
            font-size: 1rem;
            opacity: 0.9;
            margin-bottom: 2rem;
        }
        .escrow-info {
            background: rgba(255, 255, 255, 0.1);
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            backdrop-filter: blur(10px);
        }
        .escrow-info h2 {
            font-size: 1.2rem;
            margin: 0 0 0.5rem 0;
        }
        .escrow-info p {
            margin: 0.25rem 0;
            font-size: 0.9rem;
        }
        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: white;
            color: #667eea;
            text-decoration: none;
            border-radius: 25px;
            font-weight: 600;
            transition: transform 0.2s;
        }
        .btn:hover {
            transform: scale(1.05);
        }
        .help-text {
            margin-top: 2rem;
            font-size: 0.85rem;
            opacity: 0.7;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="spinner"></div>
        <h1>Opening in PeacePay App...</h1>
        <p>You will be redirected to the mobile app automatically.</p>

        <div class="escrow-info">
            <h2>Escrow Details</h2>
            <p><strong>ID:</strong> {{ $escrow->escrow_id }}</p>
            <p><strong>Title:</strong> {{ $escrow->title }}</p>
            <p><strong>Amount:</strong> {{ $escrow->amount }} {{ $escrow->escrow_currency }}</p>
        </div>

        <div id="fallback" style="display: none;">
            <p>Can't open the app?</p>
            @if($fallbackUrl != '#')
                <a href="{{ $fallbackUrl }}" class="btn">Download App</a>
            @else
                <a href="#" class="btn" onclick="window.location.href='{{ $deepLink }}'; return false;">Try Again</a>
            @endif
        </div>

        <p class="help-text">
            If the app doesn't open automatically, please ensure PeacePay is installed on your device.
        </p>
    </div>

    <script>
        // Try to open the app immediately
        window.location.href = '{{ $deepLink }}';

        // Show fallback options after 3 seconds
        setTimeout(function() {
            document.getElementById('fallback').style.display = 'block';
        }, 3000);

        // Alternative approach: Try opening in an iframe
        var iframe = document.createElement('iframe');
        iframe.style.display = 'none';
        iframe.src = '{{ $deepLink }}';
        document.body.appendChild(iframe);

        // For iOS - use a link that triggers the app
        if (/iPhone|iPad|iPod/.test(navigator.userAgent)) {
            setTimeout(function() {
                // If still on page after 2s, likely app not installed
                if (!document.hidden) {
                    document.getElementById('fallback').style.display = 'block';
                }
            }, 2000);
        }
    </script>
</body>
</html>
