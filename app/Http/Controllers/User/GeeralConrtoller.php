<?php

namespace App\Http\Controllers\User;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class GeeralConrtoller extends Controller
{
   public function pinCodeConfirm() {
      $page_title = _('Confirm Pin Code');
      return view('user.auth.confirm-pin',compact('page_title'));
   }

   public function pinCodeConfirmPost(Request $request) {
      $user = auth()->user();

      $request->validate([
         'pin_code'      => "required",
      ]);

      if($user->pin_code == $request->pin_code){
         if(session()->get('pin_code_current') == 'add_money'){
            session()->put('pin_code_confirmed_add_money',true);
         }elseif(session()->get('pin_code_current') == 'money_out'){
            session()->put('pin_code_confirmed_money_out',true);
         }

         $redirectUrl = session()->get('url_pin_code');
         return redirect($redirectUrl);
      }else{
         return back()->with(['error' => [__('Pin Code does not match')]]);
      }
   }

}
