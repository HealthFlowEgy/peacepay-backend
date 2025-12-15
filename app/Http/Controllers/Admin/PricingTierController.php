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
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:delivery,merchant,cash_out',
            'fixed_charge' => 'required|numeric|min:0',
            'percent_charge' => 'required|numeric|min:0|max:100',
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
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:delivery,merchant,cash_out',
            'fixed_charge' => 'required|numeric|min:0',
            'percent_charge' => 'required|numeric|min:0|max:100',
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
            // The pivot table will cascade delete automatically
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
            'data_target' => 'required|exists:pricing_tiers,id',
            'status' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            $error = $validator->errors()->all();
            return response()->json([
                'type' => 'error',
                'message' => [
                    'error' => $error,
                ],
            ], 400);
        }

        try {
            $pricing_tier = PricingTier::findOrFail($request->data_target);
            $pricing_tier->update(['status' => $request->status]);

            return response()->json([
                'type' => 'success',
                'message' => [
                    'success' => ['Status updated successfully!'],
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'type' => 'error',
                'message' => [
                    'error' => ['Something went wrong! Please try again.'],
                ],
            ], 500);
        }
    }

    /**
     * Assign users to pricing tiers
     */
    public function assignUsers()
    {
        $page_title = "Assign Users to Pricing Tiers";
        $pricing_tiers = PricingTier::where('status', true)->get()->groupBy('type');
        $users = User::with('pricingTiers')->latest()->paginate(20);
        return view('admin.sections.pricing-tiers.assign-users', compact('page_title', 'pricing_tiers', 'users'));
    }

    /**
     * Get user's current tier assignments
     */
    public function getUserTiers(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid user ID',
            ], 400);
        }

        try {
            $user = User::with('pricingTiers')->findOrFail($request->user_id);

            $deliveryTier = $user->pricingTiers()->where('type', PricingTier::TYPE_DELIVERY)->first();
            $merchantTier = $user->pricingTiers()->where('type', PricingTier::TYPE_MERCHANT)->first();
            $cashOutTier = $user->pricingTiers()->where('type', PricingTier::TYPE_CASH_OUT)->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'delivery_tier_id' => $deliveryTier ? $deliveryTier->id : null,
                    'merchant_tier_id' => $merchantTier ? $merchantTier->id : null,
                    'cash_out_tier_id' => $cashOutTier ? $cashOutTier->id : null,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Something went wrong!',
            ], 500);
        }
    }

    /**
     * Update user pricing tier assignment
     */
    public function updateUserTier(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'tier_type' => 'required|in:delivery,merchant,cash_out',
            'pricing_tier_id' => 'nullable|exists:pricing_tiers,id',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        try {
            $user = User::findOrFail($request->user_id);

            // If pricing_tier_id is provided, verify it matches the tier_type
            if ($request->pricing_tier_id) {
                $tier = PricingTier::findOrFail($request->pricing_tier_id);
                if ($tier->type !== $request->tier_type) {
                    return back()->withErrors(['error' => ['Tier type mismatch!']]);
                }

                // Remove existing tier of this type
                $user->pricingTiers()->where('type', $request->tier_type)->detach();

                // Attach new tier
                $user->pricingTiers()->attach($request->pricing_tier_id);
            } else {
                // Remove tier of this type
                $user->pricingTiers()->where('type', $request->tier_type)->detach();
            }

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
            'tier_type' => 'required|in:delivery,merchant,cash_out',
            'pricing_tier_id' => 'nullable|exists:pricing_tiers,id',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        try {
            if ($request->pricing_tier_id) {
                $tier = PricingTier::findOrFail($request->pricing_tier_id);
                if ($tier->type !== $request->tier_type) {
                    return back()->withErrors(['error' => ['Tier type mismatch!']]);
                }
            }

            foreach ($request->user_ids as $userId) {
                $user = User::find($userId);
                if ($user) {
                    // Remove existing tier of this type
                    $user->pricingTiers()->where('type', $request->tier_type)->detach();

                    // Attach new tier if provided
                    if ($request->pricing_tier_id) {
                        $user->pricingTiers()->attach($request->pricing_tier_id);
                    }
                }
            }

            return back()->with(['success' => ['Users assigned to pricing tier successfully!']]);
        } catch (Exception $e) {
            return back()->withErrors(['error' => ['Something went wrong! Please try again.']]);
        }
    }
}
