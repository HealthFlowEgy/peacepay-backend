<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CreateEscrow
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
        if (auth()->user()->type == "buyer") {
            return redirect()->route('user.my-escrow.index')
                ->with(['error' => [__('Create Escro For Seller Only')]]);
        }
        return $next($request);
    }
}
