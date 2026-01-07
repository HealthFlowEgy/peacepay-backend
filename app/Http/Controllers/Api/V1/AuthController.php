<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\VerifyOtpRequest;
use App\Http\Requests\ResendOtpRequest;
use App\Http\Requests\RefreshTokenRequest;
use App\Http\Resources\UserResource;
use App\Http\Resources\AuthTokenResource;
use App\Services\AuthService;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly OtpService $otpService
    ) {}

    /**
     * Register a new user account
     * 
     * @param RegisterRequest $request
     * @return JsonResponse
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            $user = $this->authService->register($request->validated());
            
            // Send OTP for phone verification
            $this->otpService->sendVerificationOtp($user);

            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء الحساب بنجاح. يرجى التحقق من رقم الهاتف',
                'data' => [
                    'user' => new UserResource($user),
                    'requires_verification' => true,
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Registration failed', [
                'phone' => $request->phone,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'فشل في إنشاء الحساب',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Authenticate user and return tokens
     * 
     * @param LoginRequest $request
     * @return JsonResponse
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->login(
                $request->phone,
                $request->password,
                $request->device_name ?? 'mobile'
            );

            if (!$result['user']->phone_verified_at) {
                // Send OTP if phone not verified
                $this->otpService->sendVerificationOtp($result['user']);
                
                return response()->json([
                    'success' => true,
                    'message' => 'يرجى التحقق من رقم الهاتف',
                    'data' => [
                        'user' => new UserResource($result['user']),
                        'requires_verification' => true,
                    ]
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'تم تسجيل الدخول بنجاح',
                'data' => [
                    'user' => new UserResource($result['user']),
                    'tokens' => new AuthTokenResource($result['tokens']),
                    'requires_verification' => false,
                ]
            ]);

        } catch (\Exception $e) {
            Log::warning('Login failed', [
                'phone' => $request->phone,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'بيانات الدخول غير صحيحة',
            ], 401);
        }
    }

    /**
     * Verify phone with OTP
     * 
     * @param VerifyOtpRequest $request
     * @return JsonResponse
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        try {
            $result = $this->otpService->verifyOtp(
                $request->phone,
                $request->otp,
                'phone_verification'
            );

            if (!$result['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                    'data' => [
                        'attempts_remaining' => $result['attempts_remaining'] ?? 0
                    ]
                ], 422);
            }

            // Mark phone as verified and issue tokens
            $user = $this->authService->markPhoneVerified($request->phone);
            $tokens = $this->authService->issueTokens($user, $request->device_name ?? 'mobile');

            return response()->json([
                'success' => true,
                'message' => 'تم التحقق من رقم الهاتف بنجاح',
                'data' => [
                    'user' => new UserResource($user),
                    'tokens' => new AuthTokenResource($tokens),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('OTP verification failed', [
                'phone' => $request->phone,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'فشل في التحقق من الرمز',
            ], 422);
        }
    }

    /**
     * Resend OTP code
     * 
     * @param ResendOtpRequest $request
     * @return JsonResponse
     */
    public function resendOtp(ResendOtpRequest $request): JsonResponse
    {
        try {
            $result = $this->otpService->resendOtp($request->phone, $request->type);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                    'data' => [
                        'retry_after' => $result['retry_after'] ?? 60
                    ]
                ], 429);
            }

            return response()->json([
                'success' => true,
                'message' => 'تم إرسال رمز التحقق',
                'data' => [
                    'expires_in' => 300, // 5 minutes
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل في إرسال الرمز',
            ], 500);
        }
    }

    /**
     * Refresh authentication tokens
     * 
     * @param RefreshTokenRequest $request
     * @return JsonResponse
     */
    public function refreshToken(RefreshTokenRequest $request): JsonResponse
    {
        try {
            $tokens = $this->authService->refreshTokens($request->refresh_token);

            return response()->json([
                'success' => true,
                'message' => 'تم تجديد الجلسة',
                'data' => [
                    'tokens' => new AuthTokenResource($tokens),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'الجلسة منتهية. يرجى تسجيل الدخول مجدداً',
            ], 401);
        }
    }

    /**
     * Logout and revoke tokens
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $this->authService->logout($request->user());

            return response()->json([
                'success' => true,
                'message' => 'تم تسجيل الخروج بنجاح',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => true,
                'message' => 'تم تسجيل الخروج',
            ]);
        }
    }

    /**
     * Get authenticated user profile
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load(['wallet', 'kycLevel']);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($user),
            ]
        ]);
    }

    /**
     * Update user profile
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|min:3|max:100',
            'email' => 'sometimes|email|unique:users,email,' . $request->user()->id,
            'national_id' => 'sometimes|string|size:14',
        ]);

        try {
            $user = $this->authService->updateProfile($request->user(), $validated);

            return response()->json([
                'success' => true,
                'message' => 'تم تحديث البيانات بنجاح',
                'data' => [
                    'user' => new UserResource($user),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل في تحديث البيانات',
            ], 422);
        }
    }

    /**
     * Change password
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        try {
            $this->authService->changePassword(
                $request->user(),
                $validated['current_password'],
                $validated['new_password']
            );

            return response()->json([
                'success' => true,
                'message' => 'تم تغيير كلمة المرور بنجاح',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Request password reset OTP
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => 'required|string|regex:/^01[0125][0-9]{8}$/',
        ]);

        try {
            $this->otpService->sendPasswordResetOtp($validated['phone']);

            return response()->json([
                'success' => true,
                'message' => 'تم إرسال رمز إعادة تعيين كلمة المرور',
            ]);

        } catch (\Exception $e) {
            // Don't reveal if phone exists
            return response()->json([
                'success' => true,
                'message' => 'إذا كان الرقم مسجلاً، سيتم إرسال رمز التحقق',
            ]);
        }
    }

    /**
     * Reset password with OTP
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => 'required|string|regex:/^01[0125][0-9]{8}$/',
            'otp' => 'required|string|size:6',
            'password' => 'required|string|min:8|confirmed',
        ]);

        try {
            $result = $this->otpService->verifyOtp(
                $validated['phone'],
                $validated['otp'],
                'password_reset'
            );

            if (!$result['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                ], 422);
            }

            $this->authService->resetPassword(
                $validated['phone'],
                $validated['password']
            );

            return response()->json([
                'success' => true,
                'message' => 'تم تغيير كلمة المرور بنجاح. يمكنك تسجيل الدخول الآن',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل في إعادة تعيين كلمة المرور',
            ], 422);
        }
    }
}
