<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;
use Illuminate\Http\JsonResponse;

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
        // if (! $request->user() || ( ! $request->user()->sms_verified)) {
        //     // For JSON requests, return a structured error response
        //     if ($request->expectsJson()) {
        //         return response()->json([
        //             'success' => false,
        //             'error' => [
        //                 'code' => 'SMS_NOT_VERIFIED',
        //                 'message' => 'Your mobile number is not verified.',
        //                 'details' => [
        //                     'action' => 'Verify your mobile number',
        //                 ]
        //             ]
        //         ], 403);
        //     }

        //     // For non-JSON requests, redirect as before
        //     return Redirect::guest(URL::route($redirectToRoute ?: 'user.code.verify.mobile'));
        // }

        return $next($request);
    }
}