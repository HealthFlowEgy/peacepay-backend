<?php
/**
 * PeacePay DSP Reassignment Service
 * 
 * New feature to allow merchant to change DSP before OTP
 */

namespace App\Services;

use App\Models\PeaceLink;
use App\Models\Wallet;
use App\Models\DspReassignmentLog;
use App\Notifications\DspRemovedNotification;
use App\Notifications\DspAssignedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class DspReassignmentService
{
    /**
     * Reassign DSP on a PeaceLink
     * 
     * Allowed only before OTP is used
     */
    public function reassignDsp(
        string $peacelinkId, 
        string $merchantId, 
        string $newDspWallet,
        string $reason = null
    ): PeaceLink {
        return DB::transaction(function () use ($peacelinkId, $merchantId, $newDspWallet, $reason) {
            $peacelink = PeaceLink::findOrFail($peacelinkId);

            // Validate merchant ownership
            if ($peacelink->merchant_id !== $merchantId) {
                throw new \Exception("Not authorized to modify this PeaceLink");
            }

            // Validate status - can only reassign before OTP
            $allowedStatuses = ['dsp_assigned', 'in_transit'];
            if (!in_array($peacelink->status, $allowedStatuses)) {
                throw new \Exception("Cannot reassign DSP at current status: {$peacelink->status}");
            }

            // Check OTP not used
            if ($peacelink->otp_used_at) {
                throw new \Exception("Cannot reassign DSP after OTP has been used");
            }

            // Validate new DSP wallet exists and is active
            $newDspWalletModel = Wallet::where('number', $newDspWallet)
                ->where('status', 'active')
                ->whereHas('user', function ($q) {
                    $q->whereJsonContains('available_roles', 'dsp');
                })
                ->first();

            if (!$newDspWalletModel) {
                throw new \Exception("Invalid DSP wallet or DSP not found");
            }

            // Store old DSP
            $oldDspWallet = $peacelink->dsp_wallet;

            // Update PeaceLink
            $peacelink->update([
                'dsp_wallet' => $newDspWallet,
                'previous_dsp_wallet' => $oldDspWallet,
                'dsp_reassigned_at' => now(),
                'dsp_reassign_reason' => $reason
            ]);

            // Log the reassignment
            DspReassignmentLog::create([
                'peacelink_id' => $peacelinkId,
                'old_dsp_wallet' => $oldDspWallet,
                'new_dsp_wallet' => $newDspWallet,
                'merchant_id' => $merchantId,
                'reason' => $reason,
                'created_at' => now()
            ]);

            // Notify old DSP
            if ($oldDspWallet) {
                $oldDsp = Wallet::where('number', $oldDspWallet)->first()?->user;
                if ($oldDsp) {
                    Notification::send($oldDsp, new DspRemovedNotification($peacelink));
                }
            }

            // Notify new DSP
            $newDsp = $newDspWalletModel->user;
            if ($newDsp) {
                Notification::send($newDsp, new DspAssignedNotification($peacelink));
            }

            return $peacelink->fresh();
        });
    }

    /**
     * Check if DSP can be reassigned
     */
    public function canReassign(PeaceLink $peacelink, string $merchantId): bool
    {
        if ($peacelink->merchant_id !== $merchantId) {
            return false;
        }

        if ($peacelink->otp_used_at) {
            return false;
        }

        return in_array($peacelink->status, ['dsp_assigned', 'in_transit']);
    }

    /**
     * Get reassignment history for a PeaceLink
     */
    public function getReassignmentHistory(string $peacelinkId): array
    {
        return DspReassignmentLog::where('peacelink_id', $peacelinkId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }
}
