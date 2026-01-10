# PeacePay Laravel Backend - Modular Architecture

## Directory Structure

```
peacepay-backend/
├── app/
│   ├── Console/
│   ├── Exceptions/
│   │   └── Handler.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── Controller.php
│   │   ├── Middleware/
│   │   │   ├── Authenticate.php
│   │   │   ├── VerifyApiSignature.php
│   │   │   ├── RateLimiter.php
│   │   │   └── ForceJsonResponse.php
│   │   └── Kernel.php
│   ├── Models/
│   │   └── .gitkeep
│   └── Providers/
│       ├── AppServiceProvider.php
│       ├── AuthServiceProvider.php
│       ├── EventServiceProvider.php
│       └── RouteServiceProvider.php
│
├── app/Modules/                          # MODULAR ARCHITECTURE
│   │
│   ├── Auth/                             # Authentication Module
│   │   ├── Config/
│   │   │   └── auth.php
│   │   ├── Controllers/
│   │   │   ├── AuthController.php
│   │   │   └── PinController.php
│   │   ├── DTOs/
│   │   │   ├── LoginRequest.php
│   │   │   └── OtpRequest.php
│   │   ├── Events/
│   │   │   ├── UserLoggedIn.php
│   │   │   └── OtpRequested.php
│   │   ├── Models/
│   │   │   ├── User.php
│   │   │   ├── UserDevice.php
│   │   │   ├── UserSession.php
│   │   │   └── OtpCode.php
│   │   ├── Repositories/
│   │   │   ├── UserRepository.php
│   │   │   └── OtpRepository.php
│   │   ├── Services/
│   │   │   ├── AuthService.php
│   │   │   ├── OtpService.php
│   │   │   ├── PinService.php
│   │   │   └── TokenService.php
│   │   ├── Routes/
│   │   │   └── api.php
│   │   └── AuthServiceProvider.php
│   │
│   ├── Wallet/                           # Wallet Module
│   │   ├── Config/
│   │   │   └── wallet.php
│   │   ├── Controllers/
│   │   │   ├── WalletController.php
│   │   │   └── TransactionController.php
│   │   ├── DTOs/
│   │   │   ├── TransferRequest.php
│   │   │   └── CashoutRequest.php
│   │   ├── Enums/
│   │   │   ├── WalletType.php
│   │   │   └── TransactionType.php
│   │   ├── Events/
│   │   │   ├── WalletCredited.php
│   │   │   ├── WalletDebited.php
│   │   │   └── CashoutRequested.php
│   │   ├── Models/
│   │   │   ├── Wallet.php
│   │   │   ├── WalletTransaction.php
│   │   │   ├── CashoutMethod.php
│   │   │   └── CashoutRequest.php
│   │   ├── Repositories/
│   │   │   ├── WalletRepository.php
│   │   │   └── TransactionRepository.php
│   │   ├── Services/
│   │   │   ├── WalletService.php
│   │   │   ├── TransactionService.php
│   │   │   └── CashoutService.php
│   │   ├── Routes/
│   │   │   └── api.php
│   │   └── WalletServiceProvider.php
│   │
│   ├── PeaceLink/                        # CORE ESCROW MODULE
│   │   ├── Config/
│   │   │   └── peacelink.php
│   │   ├── Controllers/
│   │   │   ├── PeaceLinkController.php
│   │   │   ├── ApprovalController.php
│   │   │   ├── DspAssignmentController.php
│   │   │   ├── DeliveryController.php
│   │   │   └── CancellationController.php
│   │   ├── DTOs/
│   │   │   ├── CreatePeaceLinkRequest.php
│   │   │   ├── ApprovalRequest.php
│   │   │   ├── AssignDspRequest.php
│   │   │   ├── ConfirmDeliveryRequest.php
│   │   │   └── CancellationRequest.php
│   │   ├── Enums/
│   │   │   ├── PeaceLinkStatus.php
│   │   │   ├── CancellationParty.php
│   │   │   └── PayoutType.php
│   │   ├── Events/
│   │   │   ├── PeaceLinkCreated.php
│   │   │   ├── PeaceLinkApproved.php
│   │   │   ├── DspAssigned.php
│   │   │   ├── DeliveryConfirmed.php
│   │   │   ├── PeaceLinkCanceled.php
│   │   │   └── PeaceLinkExpired.php
│   │   ├── Jobs/
│   │   │   ├── ProcessAdvancePayment.php
│   │   │   ├── ProcessDeliveryPayout.php
│   │   │   ├── ProcessCancellationRefund.php
│   │   │   └── ExpirePeaceLinks.php
│   │   ├── Listeners/
│   │   │   ├── SendApprovalNotification.php
│   │   │   ├── SendOtpNotification.php
│   │   │   └── UpdatePlatformProfit.php
│   │   ├── Models/
│   │   │   ├── PeaceLink.php
│   │   │   ├── SphHold.php
│   │   │   ├── PeaceLinkPayout.php
│   │   │   ├── DeliveryPolicy.php
│   │   │   └── FeeConfiguration.php
│   │   ├── Policies/
│   │   │   └── PeaceLinkPolicy.php
│   │   ├── Repositories/
│   │   │   ├── PeaceLinkRepository.php
│   │   │   └── FeeRepository.php
│   │   ├── Services/
│   │   │   ├── PeaceLinkService.php
│   │   │   ├── EscrowService.php
│   │   │   ├── OtpGeneratorService.php
│   │   │   ├── FeeCalculatorService.php
│   │   │   ├── PayoutService.php
│   │   │   ├── RefundService.php
│   │   │   └── CancellationService.php
│   │   ├── StateMachine/
│   │   │   ├── PeaceLinkStateMachine.php
│   │   │   ├── States/
│   │   │   │   ├── CreatedState.php
│   │   │   │   ├── PendingApprovalState.php
│   │   │   │   ├── SphActiveState.php
│   │   │   │   ├── DspAssignedState.php
│   │   │   │   ├── DeliveredState.php
│   │   │   │   ├── CanceledState.php
│   │   │   │   └── DisputedState.php
│   │   │   └── Transitions/
│   │   │       ├── ApproveTransition.php
│   │   │       ├── AssignDspTransition.php
│   │   │       ├── ConfirmDeliveryTransition.php
│   │   │       └── CancelTransition.php
│   │   ├── Routes/
│   │   │   └── api.php
│   │   └── PeaceLinkServiceProvider.php
│   │
│   ├── Dispute/                          # Dispute Module
│   │   ├── Controllers/
│   │   │   ├── DisputeController.php
│   │   │   └── AdminDisputeController.php
│   │   ├── DTOs/
│   │   │   └── OpenDisputeRequest.php
│   │   ├── Enums/
│   │   │   └── DisputeStatus.php
│   │   ├── Events/
│   │   │   ├── DisputeOpened.php
│   │   │   └── DisputeResolved.php
│   │   ├── Models/
│   │   │   ├── Dispute.php
│   │   │   └── DisputeMessage.php
│   │   ├── Services/
│   │   │   ├── DisputeService.php
│   │   │   └── ResolutionService.php
│   │   ├── Routes/
│   │   │   └── api.php
│   │   └── DisputeServiceProvider.php
│   │
│   ├── KYC/                              # KYC Module
│   │   ├── Controllers/
│   │   │   └── KycController.php
│   │   ├── Enums/
│   │   │   ├── KycLevel.php
│   │   │   └── KycStatus.php
│   │   ├── Models/
│   │   │   ├── KycDocument.php
│   │   │   └── KycVerification.php
│   │   ├── Services/
│   │   │   ├── KycService.php
│   │   │   └── NationalIdValidator.php
│   │   ├── Routes/
│   │   │   └── api.php
│   │   └── KycServiceProvider.php
│   │
│   ├── Notification/                     # Notification Module
│   │   ├── Channels/
│   │   │   ├── SmsChannel.php
│   │   │   └── PushChannel.php
│   │   ├── Models/
│   │   │   ├── NotificationTemplate.php
│   │   │   └── Notification.php
│   │   ├── Services/
│   │   │   ├── SmsService.php
│   │   │   └── PushService.php
│   │   └── NotificationServiceProvider.php
│   │
│   ├── Ledger/                           # Immutable Ledger Module
│   │   ├── Models/
│   │   │   ├── LedgerEntry.php
│   │   │   └── PlatformWallet.php
│   │   ├── Services/
│   │   │   ├── LedgerService.php
│   │   │   └── PlatformProfitService.php
│   │   └── LedgerServiceProvider.php
│   │
│   └── Admin/                            # Admin Module
│       ├── Controllers/
│       │   ├── DashboardController.php
│       │   ├── UserManagementController.php
│       │   ├── DisputeResolutionController.php
│       │   ├── CashoutApprovalController.php
│       │   └── ReportsController.php
│       ├── Services/
│       │   └── AdminService.php
│       ├── Routes/
│       │   └── api.php
│       └── AdminServiceProvider.php
│
├── config/
│   ├── app.php
│   ├── auth.php
│   ├── database.php
│   ├── logging.php
│   ├── queue.php
│   └── modules.php                       # Module registration
│
├── database/
│   ├── migrations/
│   ├── seeders/
│   └── factories/
│
├── routes/
│   ├── api.php
│   ├── web.php
│   └── channels.php
│
├── tests/
│   ├── Feature/
│   │   ├── Auth/
│   │   ├── Wallet/
│   │   ├── PeaceLink/
│   │   └── Dispute/
│   └── Unit/
│       ├── Services/
│       └── StateMachine/
│
├── .env.example
├── composer.json
├── phpunit.xml
└── README.md
```
