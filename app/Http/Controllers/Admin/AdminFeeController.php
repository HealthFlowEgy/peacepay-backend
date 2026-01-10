<?php
/**
 * Admin Fee Controller
 * Handles dynamic fee configuration
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeeConfig;
use App\Services\FeeConfigService;
use Illuminate\Http\Request;

class AdminFeeController extends Controller
{
    protected FeeConfigService $feeService;

    public function __construct(FeeConfigService $feeService)
    {
        $this->feeService = $feeService;
    }

    /**
     * Get all fee configurations
     */
    public function index()
    {
        $configs = FeeConfig::orderBy('type')->get();

        return response()->json([
            'success' => true,
            'data' => $configs,
        ]);
    }

    /**
     * Update fee configuration
     */
    public function update(Request $request, string $id)
    {
        $request->validate([
            'name' => 'sometimes|string|max:100',
            'percentage_fee' => 'sometimes|numeric|min:0|max:100',
            'fixed_fee' => 'sometimes|numeric|min:0',
            'min_amount' => 'sometimes|nullable|numeric|min:0',
            'max_amount' => 'sometimes|nullable|numeric|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        try {
            $config = $this->feeService->updateConfig($id, $request->all());

            return response()->json([
                'success' => true,
                'message' => 'تم تحديث الرسوم',
                'data' => $config,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Toggle fee active status
     */
    public function toggle(string $id)
    {
        $config = FeeConfig::findOrFail($id);
        $config->update(['is_active' => !$config->is_active]);

        return response()->json([
            'success' => true,
            'message' => $config->is_active ? 'تم تفعيل الرسوم' : 'تم تعطيل الرسوم',
            'data' => $config,
        ]);
    }

    /**
     * Calculate fee preview
     */
    public function calculate(Request $request)
    {
        $request->validate([
            'type' => 'required|string',
            'amount' => 'required|numeric|min:0',
        ]);

        try {
            $result = $this->feeService->calculateFee($request->type, $request->amount);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get fee config history
     */
    public function history(string $id)
    {
        $history = FeeConfigHistory::where('fee_config_id', $id)
            ->with('changedBy')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $history,
        ]);
    }
}
