<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\TransactionSetting;
use App\Models\Admin\BasicSettings;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TrxSettingsController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $page_title = "Fees & Charges";
        $transaction_charges = TransactionSetting::all();
        return view('admin.sections.trx-settings.index', compact(
            'page_title',
            'transaction_charges'
        ));
    }

    /**
     * Update transaction charges
     * @param Request closer
     * @return back view
     */
    public function trxChargeUpdate(Request $request)
    {
        $rules = [
            'slug'                              => 'required|string',
            $request->slug . '_fixed_charge'      => 'required|numeric',
            $request->slug . '_percent_charge'    => 'required|numeric',
        ];
        if ($request->slug == 'money-out') {
            $rules = array_merge($rules, [
                $request->slug . '_min_limit'         => 'required|numeric',
                $request->slug . '_max_limit'         => 'required|numeric',
                $request->slug . '_daily_limit'       => 'required|numeric',
                $request->slug . '_weekly_limit'      => 'required|numeric',
                $request->slug . '_monthly_limit'     => 'required|numeric',
            ]);
        }
        $validator = Validator::make($request->all(), $rules);
        $validated = $validator->validate();

        $transaction_setting = TransactionSetting::where('slug', $request->slug)->first();

        if (!$transaction_setting) return back()->with(['error' => ['Transaction charge not found!']]);
        $validated = replace_array_key($validated, $request->slug . "_");

        try {
            $transaction_setting->update($validated);
        } catch (Exception $e) {
            return back()->with(['error' => ["Something went wrong! Please try again."]]);
        }

        return back()->with(['success' => ['Charge Updated Successfully!']]);
    }

    /**
     * Display incentive balance settings
     * @return \Illuminate\Http\Response
     */
    public function incentiveBalance()
    {
        $page_title = "Incentive Balance Settings";
        $basic_settings = BasicSettings::first();
        return view('admin.sections.trx-settings.incentive-balance', compact(
            'page_title',
            'basic_settings'
        ));
    }

    /**
     * Update incentive balance settings
     * @param Request $request
     * @return back view
     */
    public function incentiveBalanceUpdate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'incentive_balance_seller' => 'required|numeric|min:0',
            'incentive_balance_buyer' => 'required|numeric|min:0',
            'incentive_balance_delivery' => 'required|numeric|min:0',
        ]);

        $validated = $validator->validate();

        $basic_settings = BasicSettings::first();

        if (!$basic_settings) return back()->with(['error' => ['Settings not found!']]);

        try {
            $basic_settings->update($validated);
        } catch (Exception $e) {
            return back()->with(['error' => ["Something went wrong! Please try again."]]);
        }

        return back()->with(['success' => ['Incentive Balance Settings Updated Successfully!']]);
    }
}
