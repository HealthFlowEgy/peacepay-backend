<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PricingTier;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Exception;

class PricingTierController extends Controller
{
    /**
     * Display a listing of the pricing tiers
     */
    public function index()
    {
        $page_title = "Pricing Tiers";
        $pricing_tiers = PricingTier::latest()->paginate(20);
        return view('admin.sections.pricing-tiers.index', compact('page_title', 'pricing_tiers'));
    }

    /**
     * Store a newly created pricing tier
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:pricing_tiers,name',
            'description' => 'nullable|string',
            'delivery_fixed_charge' => 'required|numeric|min:0',
            'delivery_percent_charge' => 'required|numeric|min:0|max:100',
            'merchant_fixed_charge' => 'required|numeric|min:0',
            'merchant_percent_charge' => 'required|numeric|min:0|max:100',
            'cash_out_fixed_charge' => 'required|numeric|min:0',
            'cash_out_percent_charge' => 'required|numeric|min:0|max:100',
            'status' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        try {
            PricingTier::create($validator->validated());
            return back()->with(['success' => ['Pricing tier created successfully!']]);
        } catch (Exception $e) {
            return back()->withErrors(['error' => ['Something went wrong! Please try again.']]);
        }
    }

    /**
     * Update the specified pricing tier
     */
    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'target' => 'required|exists:pricing_tiers,id',
            'name' => 'required|string|max:255|unique:pricing_tiers,name,' . $request->target,
            'description' => 'nullable|string',
            'delivery_fixed_charge' => 'required|numeric|min:0',
            'delivery_percent_charge' => 'required|numeric|min:0|max:100',
            'merchant_fixed_charge' => 'required|numeric|min:0',
            'merchant_percent_charge' => 'required|numeric|min:0|max:100',
            'cash_out_fixed_charge' => 'required|numeric|min:0',
            'cash_out_percent_charge' => 'required|numeric|min:0|max:100',
            'status' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        try {
            $pricing_tier = PricingTier::findOrFail($request->target);
            $pricing_tier->update($validator->validated());
            return back()->with(['success' => ['Pricing tier updated successfully!']]);
        } catch (Exception $e) {
            return back()->withErrors(['error' => ['Something went wrong! Please try again.']]);
        }
    }

    /**
     * Remove the specified pricing tier
     */
    public function delete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'target' => 'required|exists:pricing_tiers,id',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        try {
            $pricing_tier = PricingTier::findOrFail($request->target);

            // Remove tier assignment from users before deleting
            User::where('pricing_tier_id', $pricing_tier->id)->update(['pricing_tier_id' => null]);

            $pricing_tier->delete();
            return back()->with(['success' => ['Pricing tier deleted successfully!']]);
        } catch (Exception $e) {
            return back()->withErrors(['error' => ['Something went wrong! Please try again.']]);
        }
    }

    /**
     * Update status of pricing tier
     */
    public function statusUpdate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'target' => 'required|exists:pricing_tiers,id',
            'status' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        try {
            $pricing_tier = PricingTier::findOrFail($request->target);
            $pricing_tier->update(['status' => $request->status]);
            return back()->with(['success' => ['Status updated successfully!']]);
        } catch (Exception $e) {
            return back()->withErrors(['error' => ['Something went wrong! Please try again.']]);
        }
    }

    /**
     * Assign users to pricing tier
     */
    public function assignUsers()
    {
        $page_title = "Assign Users to Pricing Tiers";
        $pricing_tiers = PricingTier::where('status', true)->get();
        $users = User::with('pricingTier')->latest()->paginate(20);
        return view('admin.sections.pricing-tiers.assign-users', compact('page_title', 'pricing_tiers', 'users'));
    }

    /**
     * Update user pricing tier assignment
     */
    public function updateUserTier(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'pricing_tier_id' => 'nullable|exists:pricing_tiers,id',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        try {
            $user = User::findOrFail($request->user_id);
            $user->update(['pricing_tier_id' => $request->pricing_tier_id]);
            return back()->with(['success' => ['User pricing tier updated successfully!']]);
        } catch (Exception $e) {
            return back()->withErrors(['error' => ['Something went wrong! Please try again.']]);
        }
    }

    /**
     * Bulk assign users to pricing tier
     */
    public function bulkAssignUsers(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id',
            'pricing_tier_id' => 'nullable|exists:pricing_tiers,id',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        try {
            User::whereIn('id', $request->user_ids)->update(['pricing_tier_id' => $request->pricing_tier_id]);
            return back()->with(['success' => ['Users assigned to pricing tier successfully!']]);
        } catch (Exception $e) {
            return back()->withErrors(['error' => ['Something went wrong! Please try again.']]);
        }
    }
}
