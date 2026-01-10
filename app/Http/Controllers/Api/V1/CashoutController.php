<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashoutRequest;
use App\Http\Resources\CashoutResource;
use App\Http\Resources\CashoutCollection;
use App\Services\CashoutService;
use App\Services\WalletService;
use App\Services\FeeCalculatorService;
use App\Enums\CashoutStatus;
use App\Enums\CashoutMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CashoutController extends Controller
{
    public function __construct(
        private readonly CashoutService $cashoutService,
        private readonly WalletService $walletService,
        private readonly FeeCalculatorService $feeCalculator
    ) {}

    /**
     * Get cashout requests list
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'sometimes|string|in:pending,processing,completed,failed,cancelled',
            'per_page' => 'sometimes|integer|min:10|max:50',
        ]);

        $user = $request->user();
        $cashouts = $this->cashoutService->getUserCashouts(
            $user,
            $validated['status'] ?? null,
            $validated['per_page'] ?? 20
        );

        return response()->json([
            'success' => true,
            'data' => new CashoutCollection($cashouts),
        ]);
    }

    /**
     * Get single cashout details
     * 
     * @param Request $request
     * @param string $cashoutId
     * @return JsonResponse
     */
    public function show(Request $request, string $cashoutId): JsonResponse
    {
        $user = $request->user();
        $cashout = $this->cashoutService->getCashout($user, $cashoutId);

        if (!$cashout) {
            return response()->json([
                'success' => false,
                'message' => 'طلب السحب غير موجود',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'cashout' => new CashoutResource($cashout),
            ]
        ]);
    }

    /**
     * Create new cashout request
     * 
     * @param CashoutRequest $request
     * @return JsonResponse
     */
    public function store(CashoutRequest $request): JsonResponse
    {
        $user = $request->user();

        try {
            // Calculate fees (1.5% deducted at request time)
            $fees = $this->feeCalculator->calculateCashoutFees($request->amount);
            $netAmount = $request->amount - $fees['total'];

            // Validate balance (need full amount including fees)
            $this->walletService->validateBalance($user, $request->amount);

            // Validate cashout limits
            $this->cashoutService->validateCashoutLimits($user, $request->amount);

            // Create cashout request
            $cashout = DB::transaction(function () use ($user, $request, $fees, $netAmount) {
                // Debit full amount from wallet (fees deducted upfront)
                $this->walletService->debitForCashout(
                    $user,
                    $request->amount,
                    $fees['total']
                );

                // Create cashout record
                return $this->cashoutService->create([
                    'user_id' => $user->id,
                    'amount' => $request->amount,
                    'fee' => $fees['total'],
                    'net_amount' => $netAmount,
                    'method' => $request->method,
                    'bank_name' => $request->bank_name,
                    'account_number' => $request->account_number,
                    'account_holder_name' => $request->account_holder_name,
                    'wallet_phone' => $request->wallet_phone,
                    'wallet_provider' => $request->wallet_provider,
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء طلب السحب بنجاح',
                'data' => [
                    'cashout' => new CashoutResource($cashout),
                    'processing_time' => 'يتم معالجة طلبات السحب خلال 1-3 أيام عمل',
                ]
            ], 201);

        } catch (\App\Exceptions\InsufficientBalanceException $e) {
            return response()->json([
                'success' => false,
                'message' => 'رصيدك غير كافي',
                'data' => [
                    'available' => $e->getAvailable(),
                    'required' => $e->getRequired(),
                ]
            ], 422);

        } catch (\App\Exceptions\LimitExceededException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => [
                    'limit_type' => $e->getLimitType(),
                    'current' => $e->getCurrent(),
                    'limit' => $e->getLimit(),
                ]
            ], 422);

        } catch (\Exception $e) {
            Log::error('Cashout creation failed', [
                'user_id' => $user->id,
                'amount' => $request->amount,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'فشل في إنشاء طلب السحب',
            ], 500);
        }
    }

    /**
     * Cancel pending cashout request
     * 
     * @param Request $request
     * @param string $cashoutId
     * @return JsonResponse
     */
    public function cancel(Request $request, string $cashoutId): JsonResponse
    {
        $user = $request->user();
        $cashout = $this->cashoutService->getCashout($user, $cashoutId);

        if (!$cashout) {
            return response()->json([
                'success' => false,
                'message' => 'طلب السحب غير موجود',
            ], 404);
        }

        if ($cashout->status !== CashoutStatus::PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن إلغاء طلب السحب في حالته الحالية',
            ], 422);
        }

        try {
            $cashout = DB::transaction(function () use ($user, $cashout) {
                // Refund full amount back to wallet
                $this->walletService->refundCashout($user, $cashout);

                // Update status
                return $this->cashoutService->cancel($cashout);
            });

            return response()->json([
                'success' => true,
                'message' => 'تم إلغاء طلب السحب واسترداد المبلغ',
                'data' => [
                    'cashout' => new CashoutResource($cashout),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Cashout cancellation failed', [
                'cashout_id' => $cashoutId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'فشل في إلغاء طلب السحب',
            ], 500);
        }
    }

    /**
     * Get cashout limits and fees
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function limits(Request $request): JsonResponse
    {
        $user = $request->user();
        $limits = $this->cashoutService->getLimitsWithUsage($user);

        return response()->json([
            'success' => true,
            'data' => [
                'limits' => $limits,
                'fee_percentage' => 1.5,
                'min_amount' => 50,
                'max_amount' => $limits['daily']['remaining'],
                'processing_time' => '1-3 أيام عمل',
            ]
        ]);
    }

    /**
     * Get available cashout methods
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function methods(Request $request): JsonResponse
    {
        $methods = [
            [
                'id' => 'bank',
                'name' => 'تحويل بنكي',
                'name_en' => 'Bank Transfer',
                'description' => 'تحويل إلى حساب بنكي مصري',
                'min_amount' => 100,
                'max_amount' => 50000,
                'fee_type' => 'percentage',
                'fee_value' => 1.5,
                'processing_time' => '1-3 أيام عمل',
                'required_fields' => ['bank_name', 'account_number', 'account_holder_name'],
            ],
            [
                'id' => 'wallet',
                'name' => 'محفظة إلكترونية',
                'name_en' => 'Mobile Wallet',
                'description' => 'تحويل إلى فودافون كاش أو محافظ أخرى',
                'min_amount' => 50,
                'max_amount' => 10000,
                'fee_type' => 'percentage',
                'fee_value' => 1.5,
                'processing_time' => 'فوري - 24 ساعة',
                'required_fields' => ['wallet_phone', 'wallet_provider'],
                'providers' => [
                    ['id' => 'vodafone', 'name' => 'فودافون كاش'],
                    ['id' => 'etisalat', 'name' => 'اتصالات كاش'],
                    ['id' => 'orange', 'name' => 'اورانج كاش'],
                    ['id' => 'we', 'name' => 'WE Pay'],
                ],
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'methods' => $methods,
            ]
        ]);
    }

    /**
     * Calculate cashout fees
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function calculateFees(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:50',
        ]);

        $fees = $this->feeCalculator->calculateCashoutFees($validated['amount']);

        return response()->json([
            'success' => true,
            'data' => [
                'amount' => $validated['amount'],
                'fee_percentage' => 1.5,
                'fee_amount' => $fees['total'],
                'net_amount' => $validated['amount'] - $fees['total'],
            ]
        ]);
    }

    /**
     * Get saved bank accounts
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function savedAccounts(Request $request): JsonResponse
    {
        $user = $request->user();
        $accounts = $this->cashoutService->getSavedAccounts($user);

        return response()->json([
            'success' => true,
            'data' => [
                'accounts' => $accounts,
            ]
        ]);
    }

    /**
     * Save bank account for future use
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function saveAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'method' => 'required|string|in:bank,wallet',
            'bank_name' => 'required_if:method,bank|string',
            'account_number' => 'required_if:method,bank|string',
            'account_holder_name' => 'required_if:method,bank|string',
            'wallet_phone' => 'required_if:method,wallet|string|regex:/^01[0125][0-9]{8}$/',
            'wallet_provider' => 'required_if:method,wallet|string',
            'nickname' => 'sometimes|string|max:50',
        ]);

        $user = $request->user();

        try {
            $account = $this->cashoutService->saveAccount($user, $validated);

            return response()->json([
                'success' => true,
                'message' => 'تم حفظ الحساب بنجاح',
                'data' => [
                    'account' => $account,
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل في حفظ الحساب',
            ], 500);
        }
    }

    /**
     * Delete saved account
     * 
     * @param Request $request
     * @param string $accountId
     * @return JsonResponse
     */
    public function deleteAccount(Request $request, string $accountId): JsonResponse
    {
        $user = $request->user();

        try {
            $this->cashoutService->deleteAccount($user, $accountId);

            return response()->json([
                'success' => true,
                'message' => 'تم حذف الحساب بنجاح',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل في حذف الحساب',
            ], 404);
        }
    }

    // ==================== ADMIN ENDPOINTS ====================

    /**
     * Admin: Get all pending cashouts
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function adminPending(Request $request): JsonResponse
    {
        $this->authorize('admin');

        $cashouts = $this->cashoutService->getPendingCashouts();

        return response()->json([
            'success' => true,
            'data' => new CashoutCollection($cashouts),
        ]);
    }

    /**
     * Admin: Process cashout (mark as completed)
     * 
     * @param Request $request
     * @param string $cashoutId
     * @return JsonResponse
     */
    public function adminProcess(Request $request, string $cashoutId): JsonResponse
    {
        $this->authorize('admin');

        $validated = $request->validate([
            'reference_number' => 'required|string',
            'notes' => 'sometimes|string|max:500',
        ]);

        $cashout = $this->cashoutService->findById($cashoutId);

        if (!$cashout) {
            return response()->json([
                'success' => false,
                'message' => 'طلب السحب غير موجود',
            ], 404);
        }

        try {
            $cashout = $this->cashoutService->markCompleted(
                $cashout,
                $validated['reference_number'],
                $validated['notes'] ?? null,
                $request->user()->id
            );

            return response()->json([
                'success' => true,
                'message' => 'تم معالجة طلب السحب بنجاح',
                'data' => [
                    'cashout' => new CashoutResource($cashout),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل في معالجة طلب السحب',
            ], 500);
        }
    }

    /**
     * Admin: Reject cashout
     * 
     * @param Request $request
     * @param string $cashoutId
     * @return JsonResponse
     */
    public function adminReject(Request $request, string $cashoutId): JsonResponse
    {
        $this->authorize('admin');

        $validated = $request->validate([
            'reason' => 'required|string|min:10|max:500',
        ]);

        $cashout = $this->cashoutService->findById($cashoutId);

        if (!$cashout) {
            return response()->json([
                'success' => false,
                'message' => 'طلب السحب غير موجود',
            ], 404);
        }

        try {
            $cashout = DB::transaction(function () use ($cashout, $validated, $request) {
                // Refund user
                $this->walletService->refundCashout($cashout->user, $cashout);

                // Mark as failed
                return $this->cashoutService->markFailed(
                    $cashout,
                    $validated['reason'],
                    $request->user()->id
                );
            });

            return response()->json([
                'success' => true,
                'message' => 'تم رفض طلب السحب واسترداد المبلغ',
                'data' => [
                    'cashout' => new CashoutResource($cashout),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل في رفض طلب السحب',
            ], 500);
        }
    }
}
