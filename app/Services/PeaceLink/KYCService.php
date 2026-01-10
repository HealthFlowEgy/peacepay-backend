<?php
/**
 * KYC Service - Backend Implementation
 * Handles KYC submission, review, and approval workflow
 */

namespace App\Services;

use App\Models\KYCSubmission;
use App\Models\User;
use App\Notifications\KYCApprovedNotification;
use App\Notifications\KYCRejectedNotification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class KYCService
{
    /**
     * Submit KYC documents
     */
    public function submitKYC(string $userId, array $documents, array $personalInfo): KYCSubmission
    {
        return DB::transaction(function () use ($userId, $documents, $personalInfo) {
            $user = User::findOrFail($userId);

            // Validate documents based on requested level
            $this->validateDocuments($documents, $personalInfo['requested_level']);

            // Upload documents
            $uploadedDocs = [];
            foreach ($documents as $type => $file) {
                $path = Storage::disk('private')->putFile("kyc/{$userId}", $file);
                $uploadedDocs[] = [
                    'type' => $type,
                    'path' => $path,
                    'status' => 'pending',
                ];
            }

            // Create submission
            $submission = KYCSubmission::create([
                'user_id' => $userId,
                'current_level' => $user->kyc_level,
                'requested_level' => $personalInfo['requested_level'],
                'status' => 'pending',
                'documents' => $uploadedDocs,
                'personal_info' => $personalInfo,
            ]);

            return $submission;
        });
    }

    /**
     * Get pending submissions for admin review
     */
    public function getPendingSubmissions(int $page = 1, int $perPage = 20): array
    {
        return KYCSubmission::with('user')
            ->where('status', 'pending')
            ->orderBy('created_at', 'asc')
            ->paginate($perPage, ['*'], 'page', $page)
            ->toArray();
    }

    /**
     * Approve KYC submission
     */
    public function approveKYC(string $submissionId, string $adminId): KYCSubmission
    {
        return DB::transaction(function () use ($submissionId, $adminId) {
            $submission = KYCSubmission::findOrFail($submissionId);
            
            if ($submission->status !== 'pending' && $submission->status !== 'under_review') {
                throw new \Exception("Invalid submission status for approval");
            }

            // Update submission
            $submission->update([
                'status' => 'approved',
                'reviewed_by' => $adminId,
                'reviewed_at' => now(),
            ]);

            // Upgrade user KYC level
            $user = $submission->user;
            $user->update([
                'kyc_level' => $submission->requested_level,
                'kyc_verified_at' => now(),
            ]);

            // Update wallet limits based on new KYC level
            $this->updateWalletLimits($user);

            // Send notification
            $user->notify(new KYCApprovedNotification($submission));

            return $submission->fresh();
        });
    }

    /**
     * Reject KYC submission
     */
    public function rejectKYC(string $submissionId, string $adminId, string $reason): KYCSubmission
    {
        return DB::transaction(function () use ($submissionId, $adminId, $reason) {
            $submission = KYCSubmission::findOrFail($submissionId);

            $submission->update([
                'status' => 'rejected',
                'reviewed_by' => $adminId,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);

            // Send notification
            $submission->user->notify(new KYCRejectedNotification($submission, $reason));

            return $submission->fresh();
        });
    }

    /**
     * Validate documents based on KYC level
     */
    private function validateDocuments(array $documents, string $level): void
    {
        $required = match ($level) {
            'silver' => ['national_id_front', 'national_id_back', 'selfie'],
            'gold' => ['national_id_front', 'national_id_back', 'selfie', 'wet_signature', 'address_proof'],
            default => throw new \Exception("Invalid KYC level"),
        };

        foreach ($required as $docType) {
            if (!isset($documents[$docType])) {
                throw new \Exception("Missing required document: {$docType}");
            }
        }
    }

    /**
     * Update wallet limits based on KYC level
     */
    private function updateWalletLimits(User $user): void
    {
        $limits = match ($user->kyc_level) {
            'bronze' => ['monthly' => 5000, 'daily' => 1000],
            'silver' => ['monthly' => 50000, 'daily' => 10000],
            'gold' => ['monthly' => PHP_INT_MAX, 'daily' => 100000],
            default => ['monthly' => 5000, 'daily' => 1000],
        };

        $user->wallet->update([
            'monthly_limit' => $limits['monthly'],
            'daily_limit' => $limits['daily'],
        ]);
    }
}
