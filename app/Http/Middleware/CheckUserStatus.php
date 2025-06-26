<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class CheckUserStatus
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check()) {
            $user = Auth::user();
            
            // Check if user status is 0 (banned)
            if ($user->status == 0) {
                // Log out the user
                Auth::logout();
                
                // Invalidate the session
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                
                // Flash error message
                Session::flash('error', 'Your account has been banned. Please contact support for assistance.');
                
                // Redirect to login page
                return redirect()->route('user.login')->with([
                    'error' => ['Your account has been banned. Please contact support for assistance.']
                ]);
            }
        }
        
        return $next($request);
    }
}