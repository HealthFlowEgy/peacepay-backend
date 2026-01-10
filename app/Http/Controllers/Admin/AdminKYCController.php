<?php
/**
 * Admin KYC Controller
 * Handles KYC review endpoints
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\KYCService;
use Illuminate\Http\Request;

class AdminKYCController extends Controller
{
    protected KYCService $kycService;

    public function __construct(KYCService $kycService)
    {
        $this->kycService = $kycService;
    }

    /**
     * Get pending KYC submissions
     */
    public function index(Request $request)
    {
        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 20);
        $status = $request->input('status', 'pending');

        $submissions = $this->kycService->getPendingSubmissions($page, $perPage);

        return response()->json([
            'success' => true,
            'data' => $submissions,
        ]);
    }

    /**
     * Get single submission details
     */
    public function show(string $id)
    {
        $submission = KYCSubmission::with('user')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $submission,
        ]);
    }

    /**
     * Approve KYC submission
     */
    public function approve(string $id)
    {
        try {
            $submission = $this->kycService->approveKYC($id, auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'تم الموافقة على طلب KYC',
                'data' => $submission,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Reject KYC submission
     */
    public function reject(Request $request, string $id)
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            $submission = $this->kycService->rejectKYC($id, auth()->id(), $request->reason);

            return response()->json([
                'success' => true,
                'message' => 'تم رفض طلب KYC',
                'data' => $submission,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}
