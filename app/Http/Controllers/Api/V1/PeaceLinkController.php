<?php

namespace App\Http\Controllers\Api\V1;

use Exception;
use App\Models\User;
use App\Models\Escrow;
use App\Models\UserWallet;
use App\Models\Dispute;
use Illuminate\Http\Request;
use App\Constants\EscrowConstants;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Services\PeaceLinkService;
use App\Http\Helpers\Api\Helpers as ApiResponse;
use App\Notifications\Escrow\EscrowRequest;

/**
 * PeaceLink Controller
 * Handles all PeaceLink (SPH) API endpoints
 * Based on Re-Engineering Specification v2.0
 */
class PeaceLinkController extends Controller
{
    protected PeaceLinkService $peaceLinkService;

    public function __construct(PeaceLinkService $peaceLinkService)
    {
        $this->peaceLinkService = $peaceLinkService;
    }

    /**
     * Get PeaceLink details with proper state-based data
     * BUG FIX: OTP only visible after DSP assigned
     */
    public function show($id)
    {
        $escrow = Escrow::with(['escrowCategory', 'escrowDetails', 'user', 'userWillPay', 'delivery'])
            ->findOrFail($id);

        $user = auth()->user();
        $userRole = $this->getUserRole($escrow, $user);

        $data = [
            'id' => $escrow->id,
            'reference_number' => $escrow->reference_number ?? $escrow->escrow_id,
            'escrow_id' => $escrow->escrow_id,
            'title' => $escrow->title,
            'status' => $escrow->status,
            'status_label' => EscrowConstants::getStatusName($escrow->status),
            'status_label_ar' => EscrowConstants::getStatusNameAr($escrow->status),
            
            // Amounts
            'item_amount' => get_amount($escrow->item_amount ?: $escrow->amount, $escrow->escrow_currency),
            'delivery_fee' => get_amount($escrow->delivery_fee ?: 0, $escrow->escrow_currency),
            'total_amount' => get_amount(($escrow->item_amount ?: $escrow->amount) + ($escrow->delivery_fee ?: 0), $escrow->escrow_currency),
            'delivery_fee_paid_by' => $escrow->delivery_fee_paid_by ?? 'buyer',
            'advance_percentage' => $escrow->advance_percentage ?? 0,
            'advance_amount' => get_amount($escrow->advance_amount ?? 0, $escrow->escrow_currency),
            'advance_paid' => $escrow->advance_paid ?? false,
            
            'escrow_currency' => $escrow->escrow_currency,
            'category' => $escrow->escrowCategory->name ?? null,
            'remarks' => $escrow->remark,
            
            // Parties
            'merchant' => $this->formatUserData($escrow->user),
            'buyer' => $this->formatUserData($escrow->userWillPay),
            'dsp' => $this->formatUserData($escrow->delivery),
            
            // User's role in this transaction
            'user_role' => $userRole,
            
            // Timestamps
            'created_at' => $escrow->created_at,
            'approved_at' => $escrow->approved_at,
            'dsp_assigned_at' => $escrow->dsp_assigned_at,
            'delivered_at' => $escrow->delivered_at,
            'canceled_at' => $escrow->canceled_at,
            'expires_at' => $escrow->expires_at,
            'max_delivery_at' => $escrow->max_delivery_at,
            
            // Cancellation info
            'canceled_by' => $escrow->canceled_by,
            'cancellation_reason' => $escrow->cancellation_reason,
            
            // Actions available based on state and role
            'actions' => $this->getAvailableActions($escrow, $userRole),
            
            // OTP visibility - BUG FIX: Only show after DSP assigned
            'otp_visible' => EscrowConstants::isOtpVisibleToBuyer($escrow->status),
        ];

        // Add OTP for buyer only when DSP is assigned
        if ($userRole === 'buyer' && EscrowConstants::isOtpVisibleToBuyer($escrow->status)) {
            $data['pin_code'] = $escrow->pin_code;
        }

        // Add DSP assignment field visibility for merchant
        if ($userRole === 'merchant') {
            // BUG FIX: DSP field should be hidden until buyer approves
            $data['can_assign_dsp'] = $escrow->status === EscrowConstants::SPH_ACTIVE;
            $data['can_change_dsp'] = in_array($escrow->status, [EscrowConstants::DSP_ASSIGNED, EscrowConstants::OTP_GENERATED]) 
                && ($escrow->dsp_reassignment_count ?? 0) < EscrowConstants::MAX_DSP_REASSIGNMENTS;
        }

        $message = ['success' => [__('PeaceLink details')]];
        return ApiResponse::success($message, $data);
    }

    /**
     * Get available actions based on status and user role
     */
    protected function getAvailableActions(Escrow $escrow, string $userRole): array
    {
        $actions = [];
        $status = $escrow->status;

        switch ($userRole) {
            case 'buyer':
                // BUG FIX: Correct button label - "Cancel Order" not "Return Item"
                if (EscrowConstants::canBuyerCancel($status)) {
                    $actions[] = [
                        'action' => 'cancel',
                        'label' => 'Cancel Order',
                        'label_ar' => 'إلغاء الطلب',
                        'enabled' => true,
                    ];
                }
                
                if ($status === EscrowConstants::DELIVERED) {
                    $actions[] = [
                        'action' => 'dispute',
                        'label' => 'Report Issue',
                        'label_ar' => 'الإبلاغ عن مشكلة',
                        'enabled' => true,
                    ];
                }
                break;

            case 'merchant':
                // BUG FIX: Show Cancel button after DSP assignment
                if (EscrowConstants::canMerchantCancel($status)) {
                    $actions[] = [
                        'action' => 'cancel',
                        'label' => 'Cancel PeaceLink',
                        'label_ar' => 'إلغاء الرابط',
                        'enabled' => true,
                    ];
                }

                if ($status === EscrowConstants::SPH_ACTIVE) {
                    $actions[] = [
                        'action' => 'assign_dsp',
                        'label' => 'Assign Delivery',
                        'label_ar' => 'تعيين التوصيل',
                        'enabled' => true,
                    ];
                }

                // BUG FIX: Add "Change DSP" button
                if (in_array($status, [EscrowConstants::DSP_ASSIGNED, EscrowConstants::OTP_GENERATED])) {
                    $canChange = ($escrow->dsp_reassignment_count ?? 0) < EscrowConstants::MAX_DSP_REASSIGNMENTS;
                    $actions[] = [
                        'action' => 'change_dsp',
                        'label' => 'Change Delivery',
                        'label_ar' => 'تغيير التوصيل',
                        'enabled' => $canChange,
                        'reason' => $canChange ? null : 'Maximum reassignments reached',
                    ];
                }

                if ($status === EscrowConstants::DELIVERED) {
                    $actions[] = [
                        'action' => 'dispute',
                        'label' => 'Report Issue',
                        'label_ar' => 'الإبلاغ عن مشكلة',
                        'enabled' => true,
                    ];
                }
                break;

            case 'dsp':
                // BUG FIX: Add "Cancel Delivery" button for DSP
                if (EscrowConstants::canDspCancel($status)) {
                    $actions[] = [
                        'action' => 'cancel_delivery',
                        'label' => 'Cancel Delivery',
                        'label_ar' => 'إلغاء التوصيل',
                        'enabled' => true,
                    ];
                }

                if (in_array($status, [EscrowConstants::DSP_ASSIGNED, EscrowConstants::OTP_GENERATED])) {
                    $actions[] = [
                        'action' => 'enter_otp',
                        'label' => 'Enter OTP',
                        'label_ar' => 'إدخال رمز التحقق',
                        'enabled' => true,
                    ];
                }
                break;
        }

        return $actions;
    }

    /**
     * Assign DSP to PeaceLink
     */
    public function assignDsp(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'dsp_mobile' => 'required|string|exists:users,mobile',
            'dsp_wallet_number' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return ApiResponse::validation(['error' => $validator->errors()->all()]);
        }

        $escrow = Escrow::findOrFail($id);
        $user = auth()->user();

        // Verify user is the merchant
        if ($escrow->user_id !== $user->id) {
            return ApiResponse::error(['error' => [__('Unauthorized')]]);
        }

        // Verify status
        if ($escrow->status !== EscrowConstants::SPH_ACTIVE) {
            return ApiResponse::error(['error' => [__('DSP can only be assigned when SPH is active')]]);
        }

        $dsp = User::where('mobile', $request->dsp_mobile)->first();
        
        if (!$dsp || $dsp->type !== 'delivery') {
            return ApiResponse::error(['error' => [__('Invalid DSP wallet')]]);
        }

        try {
            $result = $this->peaceLinkService->assignDsp(
                $escrow, 
                $dsp, 
                $request->dsp_wallet_number
            );

            // TODO: Send OTP to buyer via SMS
            // SmsService::sendOtp($escrow->userWillPay->mobile, $result['otp']);

            return ApiResponse::success(['success' => [__('DSP assigned successfully')]], [
                'escrow' => $result['escrow'],
                'otp_sent' => true,
            ]);
        } catch (Exception $e) {
            return ApiResponse::error(['error' => [$e->getMessage()]]);
        }
    }

    /**
     * Change DSP (reassign)
     */
    public function changeDsp(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'dsp_mobile' => 'required|string|exists:users,mobile',
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return ApiResponse::validation(['error' => $validator->errors()->all()]);
        }

        $escrow = Escrow::findOrFail($id);
        $user = auth()->user();

        // Verify user is the merchant
        if ($escrow->user_id !== $user->id) {
            return ApiResponse::error(['error' => [__('Unauthorized')]]);
        }

        // Verify status allows reassignment
        if (!in_array($escrow->status, [EscrowConstants::DSP_ASSIGNED, EscrowConstants::OTP_GENERATED])) {
            return ApiResponse::error(['error' => [__('Cannot change DSP in current state')]]);
        }

        // Check reassignment limit
        if (($escrow->dsp_reassignment_count ?? 0) >= EscrowConstants::MAX_DSP_REASSIGNMENTS) {
            return ApiResponse::error(['error' => [__('Maximum DSP reassignments reached')]]);
        }

        $newDsp = User::where('mobile', $request->dsp_mobile)->first();
        
        if (!$newDsp || $newDsp->type !== 'delivery') {
            return ApiResponse::error(['error' => [__('Invalid DSP wallet')]]);
        }

        try {
            // Reset to SPH_ACTIVE first
            $escrow->status = EscrowConstants::SPH_ACTIVE;
            $escrow->save();

            // Then assign new DSP
            $result = $this->peaceLinkService->assignDsp($escrow, $newDsp);

            return ApiResponse::success(['success' => [__('DSP changed successfully')]], [
                'escrow' => $result['escrow'],
            ]);
        } catch (Exception $e) {
            return ApiResponse::error(['error' => [$e->getMessage()]]);
        }
    }

    /**
     * Cancel PeaceLink
     */
    public function cancel(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return ApiResponse::validation(['error' => $validator->errors()->all()]);
        }

        $escrow = Escrow::findOrFail($id);
        $user = auth()->user();
        $userRole = $this->getUserRole($escrow, $user);

        // Determine cancellation party
        $canceledBy = match($userRole) {
            'buyer' => EscrowConstants::CANCEL_BY_BUYER,
            'merchant' => EscrowConstants::CANCEL_BY_MERCHANT,
            'dsp' => EscrowConstants::CANCEL_BY_DSP,
            default => null,
        };

        if (!$canceledBy) {
            return ApiResponse::error(['error' => [__('Unauthorized')]]);
        }

        // Verify cancellation is allowed
        $canCancel = match($canceledBy) {
            EscrowConstants::CANCEL_BY_BUYER => EscrowConstants::canBuyerCancel($escrow->status),
            EscrowConstants::CANCEL_BY_MERCHANT => EscrowConstants::canMerchantCancel($escrow->status),
            EscrowConstants::CANCEL_BY_DSP => EscrowConstants::canDspCancel($escrow->status),
            default => false,
        };

        if (!$canCancel) {
            return ApiResponse::error(['error' => [__('Cannot cancel in current state')]]);
        }

        try {
            // Special handling for DSP cancel - just remove DSP, don't cancel the whole transaction
            if ($canceledBy === EscrowConstants::CANCEL_BY_DSP) {
                $escrow->delivery_id = null;
                $escrow->dsp_wallet_number = null;
                $escrow->otp_hash = null;
                $escrow->otp_generated_at = null;
                $escrow->otp_expires_at = null;
                $escrow->otp_attempts = 0;
                $escrow->status = EscrowConstants::SPH_ACTIVE;
                $escrow->dsp_assigned_at = null;
                $escrow->save();

                return ApiResponse::success(['success' => [__('Delivery canceled. Awaiting new DSP assignment.')]], [
                    'escrow' => $escrow->fresh(),
                ]);
            }

            $result = $this->peaceLinkService->processCancellation(
                $escrow,
                $canceledBy,
                $request->reason
            );

            return ApiResponse::success(['success' => [__('PeaceLink canceled successfully')]], [
                'escrow' => $result['escrow'],
                'refund_details' => $result['refund_details'],
            ]);
        } catch (Exception $e) {
            return ApiResponse::error(['error' => [$e->getMessage()]]);
        }
    }

    /**
     * Verify OTP and complete delivery
     */
    public function verifyOtp(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'otp' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return ApiResponse::validation(['error' => $validator->errors()->all()]);
        }

        $escrow = Escrow::findOrFail($id);
        $user = auth()->user();

        // Verify user is the DSP
        if ($escrow->delivery_id !== $user->id) {
            return ApiResponse::error(['error' => [__('Unauthorized')]]);
        }

        // Verify status
        if (!in_array($escrow->status, [EscrowConstants::DSP_ASSIGNED, EscrowConstants::OTP_GENERATED])) {
            return ApiResponse::error(['error' => [__('Invalid state for OTP verification')]]);
        }

        try {
            // Verify OTP
            if (!$this->peaceLinkService->verifyOtp($escrow, $request->otp)) {
                return ApiResponse::error(['error' => [__('Invalid OTP')]]);
            }

            // Process delivery
            $result = $this->peaceLinkService->processDelivery($escrow, $user);

            return ApiResponse::success(['success' => [__('Delivery confirmed successfully')]], [
                'escrow' => $result['escrow'],
                'merchant_payout' => $result['merchant_payout'],
            ]);
        } catch (Exception $e) {
            return ApiResponse::error(['error' => [$e->getMessage()]]);
        }
    }

    /**
     * Open a dispute
     */
    public function openDispute(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:1000',
            'reason_ar' => 'nullable|string|max:1000',
            'evidence_urls' => 'nullable|array',
            'evidence_urls.*' => 'url',
        ]);

        if ($validator->fails()) {
            return ApiResponse::validation(['error' => $validator->errors()->all()]);
        }

        $escrow = Escrow::findOrFail($id);
        $user = auth()->user();
        $userRole = $this->getUserRole($escrow, $user);

        // Verify user is involved in the transaction
        if (!in_array($userRole, ['buyer', 'merchant', 'dsp'])) {
            return ApiResponse::error(['error' => [__('Unauthorized')]]);
        }

        // Check if dispute already exists
        $existingDispute = Dispute::where('escrow_id', $escrow->id)
            ->whereNotIn('status', [EscrowConstants::DISPUTE_RESOLVED_BUYER, EscrowConstants::DISPUTE_RESOLVED_MERCHANT, EscrowConstants::DISPUTE_RESOLVED_SPLIT])
            ->first();

        if ($existingDispute) {
            return ApiResponse::error(['error' => [__('A dispute already exists for this transaction')]]);
        }

        try {
            DB::beginTransaction();

            // Create dispute
            $dispute = Dispute::create([
                'escrow_id' => $escrow->id,
                'opened_by' => $user->id,
                'opened_by_role' => $userRole,
                'status' => EscrowConstants::DISPUTE_OPEN,
                'reason' => $request->reason,
                'reason_ar' => $request->reason_ar,
                'evidence_urls' => $request->evidence_urls,
            ]);

            // Update escrow status
            $escrow->status = EscrowConstants::ACTIVE_DISPUTE;
            $escrow->save();

            DB::commit();

            return ApiResponse::success(['success' => [__('Dispute opened successfully')]], [
                'dispute' => $dispute,
                'escrow' => $escrow->fresh(),
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return ApiResponse::error(['error' => [$e->getMessage()]]);
        }
    }

    /**
     * Get user's role in the escrow
     */
    protected function getUserRole(Escrow $escrow, User $user): string
    {
        if ($escrow->user_id === $user->id) {
            return 'merchant';
        }
        if ($escrow->buyer_or_seller_id === $user->id) {
            return 'buyer';
        }
        if ($escrow->delivery_id === $user->id) {
            return 'dsp';
        }
        return 'unknown';
    }

    /**
     * Format user data for response
     */
    protected function formatUserData(?User $user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->fullname ?? $user->firstname . ' ' . $user->lastname,
            'mobile' => $user->mobile,
            'type' => $user->type,
        ];
    }
}
