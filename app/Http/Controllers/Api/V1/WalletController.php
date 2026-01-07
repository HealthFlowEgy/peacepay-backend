<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddMoneyRequest;
use App\Http\Requests\SendMoneyRequest;
use App\Http\Requests\SearchUserRequest;
use App\Http\Resources\WalletResource;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\TransactionCollection;
use App\Http\Resources\UserSearchResource;
use App\Services\WalletService;
use App\Services\PaymentGatewayService;
use App\Services\FeeCalculatorService;
use App\Enums\TransactionType;
use App\Enums\TransactionStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletController extends Controller
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly PaymentGatewayService $paymentGateway,
        private readonly FeeCalculatorService $feeCalculator
    ) {}

    /**
     * Get wallet details with balance and limits
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $wallet = $this->walletService->getWalletDetails($user);

        return response()->json([
            'success' => true,
            'data' => [
                'wallet' => new WalletResource($wallet),
            ]
        ]);
    }

    /**
     * Get wallet balance summary
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function balance(Request $request): JsonResponse
    {
        $user = $request->user();
        $balances = $this->walletService->getBalances($user);

        return response()->json([
            'success' => true,
            'data' => [
                'available' => $balances['available'],
                'hold' => $balances['hold'],
                'pending_cashout' => $balances['pending_cashout'],
                'total' => $balances['total'],
                'currency' => 'EGP',
            ]
        ]);
    }

    /**
     * Get transaction history with filters
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function transactions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'sometimes|string|in:all,credit,debit,peacelink',
            'status' => 'sometimes|string|in:pending,completed,failed,cancelled',
            'from_date' => 'sometimes|date|before_or_equal:to_date',
            'to_date' => 'sometimes|date|after_or_equal:from_date',
            'per_page' => 'sometimes|integer|min:10|max:100',
        ]);

        $user = $request->user();
        $transactions = $this->walletService->getTransactions(
            $user,
            $validated['type'] ?? 'all',
            $validated['status'] ?? null,
            $validated['from_date'] ?? null,
            $validated['to_date'] ?? null,
            $validated['per_page'] ?? 20
        );

        return response()->json([
            'success' => true,
            'data' => new TransactionCollection($transactions),
        ]);
    }

    /**
     * Get single transaction details
     * 
     * @param Request $request
     * @param string $transactionId
     * @return JsonResponse
     */
    public function transactionDetails(Request $request, string $transactionId): JsonResponse
    {
        $user = $request->user();
        $transaction = $this->walletService->getTransaction($user, $transactionId);

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'المعاملة غير موجودة',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'transaction' => new TransactionResource($transaction),
            ]
        ]);
    }

    /**
     * Initialize add money request
     * 
     * @param AddMoneyRequest $request
     * @return JsonResponse
     */
    public function addMoney(AddMoneyRequest $request): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Calculate fees
            $fees = $this->feeCalculator->calculateAddMoneyFees(
                $request->amount,
                $request->payment_method
            );

            // Validate against limits
            $this->walletService->validateAddMoneyLimits($user, $request->amount);

            // Create pending transaction
            $transaction = DB::transaction(function () use ($user, $request, $fees) {
                return $this->walletService->createAddMoneyTransaction(
                    $user,
                    $request->amount,
                    $request->payment_method,
                    $fees
                );
            });

            // Initialize payment gateway
            $paymentData = $this->paymentGateway->initializePayment(
                $transaction,
                $request->payment_method,
                $request->all()
            );

            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء طلب الإيداع',
                'data' => [
                    'transaction_id' => $transaction->uuid,
                    'amount' => $request->amount,
                    'fees' => $fees,
                    'total' => $request->amount + $fees['total'],
                    'payment_method' => $request->payment_method,
                    'payment_data' => $paymentData,
                    'expires_at' => now()->addMinutes(30)->toIso8601String(),
                ]
            ]);

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
            Log::error('Add money failed', [
                'user_id' => $user->id,
                'amount' => $request->amount,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'فشل في إنشاء طلب الإيداع',
            ], 500);
        }
    }

    /**
     * Handle payment gateway callback/webhook
     * 
     * @param Request $request
     * @param string $provider
     * @return JsonResponse
     */
    public function paymentCallback(Request $request, string $provider): JsonResponse
    {
        try {
            $result = $this->paymentGateway->handleCallback($provider, $request->all());

            if ($result['success']) {
                // Credit wallet
                DB::transaction(function () use ($result) {
                    $this->walletService->creditWallet(
                        $result['transaction'],
                        $result['net_amount']
                    );
                });
            }

            return response()->json([
                'success' => true,
                'message' => 'تم معالجة الدفع',
            ]);

        } catch (\Exception $e) {
            Log::error('Payment callback failed', [
                'provider' => $provider,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'فشل في معالجة الدفع',
            ], 500);
        }
    }

    /**
     * Search for user by phone number
     * 
     * @param SearchUserRequest $request
     * @return JsonResponse
     */
    public function searchUser(SearchUserRequest $request): JsonResponse
    {
        $currentUser = $request->user();
        
        $user = $this->walletService->searchUserByPhone(
            $request->phone,
            $currentUser->id
        );

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'المستخدم غير موجود',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserSearchResource($user),
            ]
        ]);
    }

    /**
     * Send money to another user
     * 
     * @param SendMoneyRequest $request
     * @return JsonResponse
     */
    public function sendMoney(SendMoneyRequest $request): JsonResponse
    {
        $sender = $request->user();

        try {
            // Validate recipient exists
            $recipient = $this->walletService->searchUserByPhone(
                $request->recipient_phone,
                $sender->id
            );

            if (!$recipient) {
                return response()->json([
                    'success' => false,
                    'message' => 'المستلم غير موجود',
                ], 404);
            }

            // Validate balance and limits
            $this->walletService->validateTransferLimits($sender, $request->amount);
            $this->walletService->validateBalance($sender, $request->amount);

            // Execute transfer
            $result = DB::transaction(function () use ($sender, $recipient, $request) {
                return $this->walletService->transfer(
                    $sender,
                    $recipient,
                    $request->amount,
                    $request->note
                );
            });

            return response()->json([
                'success' => true,
                'message' => 'تم التحويل بنجاح',
                'data' => [
                    'transaction' => new TransactionResource($result['sender_transaction']),
                    'recipient' => [
                        'name' => $recipient->masked_name,
                        'phone' => $recipient->masked_phone,
                    ],
                    'new_balance' => $result['new_balance'],
                ]
            ]);

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
            Log::error('Transfer failed', [
                'sender_id' => $sender->id,
                'recipient_phone' => $request->recipient_phone,
                'amount' => $request->amount,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'فشل في إتمام التحويل',
            ], 500);
        }
    }

    /**
     * Get wallet limits and usage
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function limits(Request $request): JsonResponse
    {
        $user = $request->user();
        $limits = $this->walletService->getLimitsWithUsage($user);

        return response()->json([
            'success' => true,
            'data' => [
                'kyc_level' => $user->kyc_level,
                'limits' => $limits,
            ]
        ]);
    }

    /**
     * Get available payment methods
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function paymentMethods(Request $request): JsonResponse
    {
        $methods = $this->paymentGateway->getAvailablePaymentMethods();

        return response()->json([
            'success' => true,
            'data' => [
                'methods' => $methods,
            ]
        ]);
    }

    /**
     * Calculate fees for an operation
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function calculateFees(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operation' => 'required|string|in:add_money,send_money,peacelink',
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'sometimes|string',
        ]);

        $fees = match ($validated['operation']) {
            'add_money' => $this->feeCalculator->calculateAddMoneyFees(
                $validated['amount'],
                $validated['payment_method'] ?? 'card'
            ),
            'send_money' => $this->feeCalculator->calculateTransferFees(
                $validated['amount']
            ),
            'peacelink' => $this->feeCalculator->calculatePeaceLinkFees(
                $validated['amount']
            ),
            default => ['total' => 0],
        };

        return response()->json([
            'success' => true,
            'data' => [
                'amount' => $validated['amount'],
                'fees' => $fees,
                'total' => $validated['amount'] + $fees['total'],
            ]
        ]);
    }

    /**
     * Export transactions to CSV/PDF
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function exportTransactions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'format' => 'required|string|in:csv,pdf',
            'from_date' => 'sometimes|date',
            'to_date' => 'sometimes|date',
        ]);

        $user = $request->user();

        try {
            $exportUrl = $this->walletService->exportTransactions(
                $user,
                $validated['format'],
                $validated['from_date'] ?? null,
                $validated['to_date'] ?? null
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'download_url' => $exportUrl,
                    'expires_at' => now()->addHours(24)->toIso8601String(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل في تصدير المعاملات',
            ], 500);
        }
    }
}
