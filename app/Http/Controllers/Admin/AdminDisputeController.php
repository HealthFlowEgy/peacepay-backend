<?php
/**
 * Admin Dispute Controller
 * Handles dispute review and resolution
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\PeaceLink;
use App\Services\DisputeService;
use Illuminate\Http\Request;

class AdminDisputeController extends Controller
{
    protected DisputeService $disputeService;

    public function __construct(DisputeService $disputeService)
    {
        $this->disputeService = $disputeService;
    }

    /**
     * Get all disputes
     */
    public function index(Request $request)
    {
        $status = $request->input('status', 'open');
        
        $disputes = Dispute::with(['peacelink', 'initiator'])
            ->when($status !== 'all', fn($q) => $q->where('status', $status))
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $disputes,
        ]);
    }

    /**
     * Get dispute details with evidence
     */
    public function show(string $id)
    {
        $dispute = Dispute::with([
            'peacelink.buyer',
            'peacelink.merchant',
            'peacelink.dsp',
            'evidence',
            'timeline',
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $dispute,
        ]);
    }

    /**
     * Resolve dispute
     */
    public function resolve(Request $request, string $id)
    {
        $request->validate([
            'decision' => 'required|in:buyer_favor,merchant_favor,partial,dismissed',
            'buyer_refund' => 'required_if:decision,buyer_favor,partial|numeric|min:0',
            'merchant_payout' => 'required_if:decision,merchant_favor,partial|numeric|min:0',
            'dsp_payout' => 'sometimes|numeric|min:0',
            'notes' => 'required|string|max:1000',
        ]);

        try {
            $result = $this->disputeService->resolveDispute(
                $id,
                auth()->id(),
                $request->decision,
                [
                    'buyer_refund' => $request->buyer_refund ?? 0,
                    'merchant_payout' => $request->merchant_payout ?? 0,
                    'dsp_payout' => $request->dsp_payout ?? 0,
                ],
                $request->notes
            );

            return response()->json([
                'success' => true,
                'message' => 'تم حل النزاع',
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
     * Add admin note to dispute
     */
    public function addNote(Request $request, string $id)
    {
        $request->validate([
            'note' => 'required|string|max:500',
        ]);

        $dispute = Dispute::findOrFail($id);

        $dispute->timeline()->create([
            'action' => 'admin_note',
            'by' => 'admin',
            'user_id' => auth()->id(),
            'notes' => $request->note,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إضافة الملاحظة',
        ]);
    }

    /**
     * Send message to party
     */
    public function sendMessage(Request $request, string $id)
    {
        $request->validate([
            'target' => 'required|in:buyer,merchant,dsp',
            'message' => 'required|string|max:500',
        ]);

        $dispute = Dispute::with(['peacelink.buyer', 'peacelink.merchant', 'peacelink.dsp'])->findOrFail($id);

        // Get target user
        $targetUser = match ($request->target) {
            'buyer' => $dispute->peacelink->buyer,
            'merchant' => $dispute->peacelink->merchant,
            'dsp' => $dispute->peacelink->dsp,
        };

        if (!$targetUser) {
            return response()->json(['success' => false, 'error' => 'Target user not found'], 400);
        }

        // Send notification/message
        $targetUser->notify(new \App\Notifications\DisputeMessageNotification($dispute, $request->message));

        // Log in timeline
        $dispute->timeline()->create([
            'action' => 'admin_message',
            'by' => 'admin',
            'user_id' => auth()->id(),
            'notes' => "رسالة إلى {$request->target}: {$request->message}",
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال الرسالة',
        ]);
    }
}
