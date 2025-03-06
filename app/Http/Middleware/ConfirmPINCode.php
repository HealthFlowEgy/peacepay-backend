<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ConfirmPINCode
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
        if(strpos($request->path(),'user/add-money') === 0 && session()->get('pin_code_confirmed_add_money') != true){
            session()->put('url_pin_code', $request->fullUrl());
            session()->put('pin_code_current', 'add_money');
            return redirect()->route('user.pin.code.confirm');
        }

        if(strpos($request->path(),'user/money-out') === 0 && session()->get('pin_code_confirmed_money_out') != true){
            session()->put('url_pin_code', $request->fullUrl());
            session()->put('pin_code_current', 'money_out');
            return redirect()->route('user.pin.code.confirm');
        }

        return $next($request);
    }
}
