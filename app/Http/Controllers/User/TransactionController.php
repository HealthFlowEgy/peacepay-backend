<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Constants\PaymentGatewayConst;
use App\Models\User;
use App\Models\UserWallet;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    public function slugValue($slug)
    {
        $values =  [
            'add-money'             => PaymentGatewayConst::TYPEADDMONEY,
            'money-out'             => PaymentGatewayConst::TYPEMONEYOUT,
            'money-exchange'        => PaymentGatewayConst::TYPEMONEYEXCHANGE,
        ];

        if (!array_key_exists($slug, $values)) return abort(404);
        return $values[$slug];
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index($slug = null)
    {
        if ($slug != null) {
            $transactions = Transaction::where(['user_id' => auth()->user()->id, 'type' => $this->slugValue($slug)])->orderByDesc("id")->paginate(12);
            $page_title = ucwords(remove_speacial_char($slug, " ")) . " Log";
        } else {
            $transactions = Transaction::where('user_id', auth()->user()->id)->orderByDesc("id")->paginate(12);
            $page_title = __("Transaction Log");
        }
        return view('user.sections.transactions.index', compact('transactions', 'page_title'));
    }

    public function transfer(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric',
            'to'     => 'required|numeric',
        ]);

        $user = auth()->user();
        $userWallet = UserWallet::where('user_id', $user->id)->first();
        $toUser = User::where('mobile', $request->to)->where('mobile', '!=', $user->mobile)->first();
        $toUserWallet = UserWallet::where('user_id', $toUser?->id)->first();

        if (!$toUser) {
            return redirect()->back()->with(['error' => [__('User not found')]]);
        }

        if ($userWallet->balance < $request->amount) {
            return redirect()->back()->with(['error' => [__('Insufficient balance')]]);
        }

        DB::beginTransaction();
        try {
            $userWallet->balance -= $request->amount;
            $userWallet->save();

            $toUserWallet->balance += $request->amount;
            $toUserWallet->save();

            $trx_id = 'ME' . getTrxNum();

            $transaction = Transaction::create([
                'user_id' => $user->id,
                'user_wallet_id' => $user->id,
                'total_payable' => $request->amount,
                'type' => PaymentGatewayConst::TRANSFER,
                'status' => 1,
                'trx_id' => $trx_id,
                'attribute' => 'SEND',
                'sender_currency_code' => $userWallet->currency->code,
                'available_balance' => $userWallet->balance,
                'exchange_rate' => 1,
            ]);

            $toTransaction = Transaction::create([
                'user_id' => $toUser->id,
                'user_wallet_id' => $toUser->id,
                'total_payable' => $request->amount,
                'type' => PaymentGatewayConst::TRANSFER,
                'status' => 1,
                'trx_id' => $trx_id,
                'attribute' => 'RECEIVED',
                'sender_currency_code' => $userWallet->currency->code,
                'available_balance' => $toUserWallet->balance,
                'exchange_rate' => 1,
            ]);

            DB::commit();
        } catch (\Exception $e) {
            dd($e);
            DB::rollBack();
            return redirect()->back()->with(['error' => [__('Transaction failed')]]);
        }

        return redirect()->back()->with(['message' => __('Transfer successful')]);
    }
}
