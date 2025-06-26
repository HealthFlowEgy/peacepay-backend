<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;
use App\Models\UserDevice;
use Carbon\Carbon;

class EnsureSMSVerified
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string|null  $redirectToRoute
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse|null
     */
    public function handle($request, Closure $next, $redirectToRoute = null)
    {
        $user = $request->user();
        
        if (!$user) {
            // Handle case when no user is logged in
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }
            return redirect()->route('login');
        }

        // Generate device fingerprint
        $fingerprint = $this->generateDeviceFingerprint($request);
        
        // Check if device is registered for this user
        $device = UserDevice::where('user_id', $user->id)
            ->where('device_fingerprint', $fingerprint)
            ->first();

        // If user is verified but using a new device, reset sms_verified status
        if ($user->sms_verified && !$device && $request->header('Content-Type') != 'application/json') {
            $user->sms_verified = false;
            $user->save();
            
            // Create new unverified device record
            UserDevice::create([
                'user_id' => $user->id,
                'device_fingerprint' => $fingerprint,
                'ip_address' => $request->ip(),
                'user_agent' => $request->header('agent') ?? $request->userAgent(),
                'last_used_at' => Carbon::now(),
            ]);
        }
        
        // Now check if SMS is verified
        if (!$user->sms_verified) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'SMS_NOT_VERIFIED',
                        'message' => 'Your mobile number is not verified for this device.',
                        'details' => [
                            'action' => 'Verify your mobile number',
                        ]
                    ]
                ], 403);
            }
            
            return Redirect::guest(URL::route($redirectToRoute ?: 'user.code.verify.mobile'));
        }
        
        // If device exists but we got here, it means user is verified on this device
        // Update the last_used_at timestamp
        if ($device) {
            $device->update([
                'last_used_at' => Carbon::now(),
                'ip_address' => $request->ip(),
            ]);
        } else {
            // Create a new verified device record
            UserDevice::create([
                'user_id' => $user->id,
                'device_fingerprint' => $fingerprint,
                'ip_address' => $request->ip(),
                'user_agent' => $request->header('agent') ?? $request->userAgent(),
                'last_used_at' => Carbon::now(),
            ]);
        }
        
        return $next($request);
    }
    
    /**
     * Generate a device fingerprint from the request
     */
    private function generateDeviceFingerprint(Request $request)
    {
        // Combine multiple factors to create a unique device identifier
        $data = [
            'user_agent' => $request->header('agent') ?? $request->userAgent(),
            'ip' => $request->ip(),
            // You can add more factors if available, like:
            // 'accept_language' => $request->header('Accept-Language'),
            // 'screen_resolution' => $request->input('screen_resolution'), // Would need to be sent from frontend
        ];
        
        // Create a hash of the combined data
        return hash('sha256', json_encode($data));
    }
}