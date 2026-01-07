<?php

declare(strict_types=1);

namespace App\Modules\PeaceLink\Events;

use App\Modules\PeaceLink\Models\PeaceLink;
use App\Modules\PeaceLink\DTOs\CancellationResult;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Base PeaceLink Event
 */
abstract class PeaceLinkEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PeaceLink $peaceLink,
    ) {}
}

/**
 * Fired when a new PeaceLink is created
 */
class PeaceLinkCreated extends PeaceLinkEvent {}

/**
 * Fired when buyer approves and pays for PeaceLink
 */
class PeaceLinkApproved extends PeaceLinkEvent {}

/**
 * Fired when DSP is assigned to PeaceLink
 */
class DspAssigned extends PeaceLinkEvent {}

/**
 * Fired when DSP is reassigned
 */
class DspReassigned extends PeaceLinkEvent
{
    public function __construct(
        PeaceLink $peaceLink,
        public readonly string $previousDspId,
        public readonly string $reason,
    ) {
        parent::__construct($peaceLink);
    }
}

/**
 * Fired when OTP is generated and sent
 */
class OtpGenerated extends PeaceLinkEvent {}

/**
 * Fired when delivery is confirmed with OTP
 */
class DeliveryConfirmed extends PeaceLinkEvent {}

/**
 * Fired when PeaceLink is canceled
 */
class PeaceLinkCanceled extends PeaceLinkEvent
{
    public function __construct(
        PeaceLink $peaceLink,
        public readonly CancellationResult $result,
    ) {
        parent::__construct($peaceLink);
    }
}

/**
 * Fired when PeaceLink expires
 */
class PeaceLinkExpired extends PeaceLinkEvent {}

/**
 * Fired when a dispute is opened
 */
class DisputeOpened extends PeaceLinkEvent
{
    public function __construct(
        PeaceLink $peaceLink,
        public readonly string $reason,
    ) {
        parent::__construct($peaceLink);
    }
}

/**
 * Fired when a dispute is resolved
 */
class DisputeResolved extends PeaceLinkEvent
{
    public function __construct(
        PeaceLink $peaceLink,
        public readonly string $resolution,
        public readonly array $payouts,
    ) {
        parent::__construct($peaceLink);
    }
}
