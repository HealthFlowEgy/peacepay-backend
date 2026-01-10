# PeacePay Backend Bug Fixes Required

**Date:** January 10, 2026
**Based on:** Test Cases Report, PeaceLink Test Scenarios

## Critical Backend Bugs

### BUG-001: Cancellation Fee Logic (Before DSP Assignment)

**Issue:** When buyer cancels SPH before DSP assignment, system still deducts PeacePay delivery commission.

**Current Behavior:**
- System runs DSP-related payout/fee logic even when no DSP is assigned
- PeacePay profit increased when it shouldn't

**Expected Behavior:**
- Full refund of SPH amount (item + delivery) to buyer
- No payout to merchant or DSP
- No platform fees deducted
- PeacePay profit unchanged

**Fix Required:**
```python
# In cancellation handler
def cancel_peacelink(peacelink_id, cancelled_by):
    peacelink = get_peacelink(peacelink_id)
    
    if peacelink.dsp_assigned:
        # Process DSP payout logic
        process_dsp_payout(peacelink)
        process_platform_fee(peacelink)
    else:
        # Skip DSP payout, refund full SPH to buyer
        refund_full_sph_to_buyer(peacelink)
        # DO NOT call process_platform_fee()
        
    log_cancellation(peacelink, cancelled_by, dsp_assigned=peacelink.dsp_assigned)
```

**Log Entry:**
```
"No delivery agent assigned. Full refund issued to buyer. No profit taken."
```

---

### BUG-002: DSP Not Paid on Admin Release to Merchant

**Issue:** When admin uses "Release to Seller" after DSP is assigned, DSP does not receive delivery fee. Delivery fee mistakenly included in merchant payout.

**Current Behavior:**
- Merchant overpaid by 100 EGP (delivery fee)
- DSP underpaid by 99.5 EGP
- Platform did not collect 0.5 EGP DSP fee

**Expected Behavior:**
- DSP gets: delivery_fee - 0.5% = 99.5 EGP
- Merchant gets: item_price - (0.5% + 3 EGP) = 992.5 EGP
- PeacePay earns: 7.5 (merchant) + 0.5 (DSP) = 8 EGP

**Fix Required:**
```python
# In admin release_to_seller handler
def release_to_seller(peacelink_id, admin_id):
    peacelink = get_peacelink(peacelink_id)
    
    if peacelink.dsp_assigned:
        # MUST pay DSP even on admin release
        dsp_payout = peacelink.delivery_fee * (1 - DSP_FEE_PERCENTAGE)
        dsp_fee = peacelink.delivery_fee * DSP_FEE_PERCENTAGE
        
        credit_wallet(peacelink.dsp_wallet, dsp_payout)
        credit_platform_profit(dsp_fee)
        
        # Merchant gets item amount minus merchant fee
        merchant_payout = peacelink.item_price * (1 - MERCHANT_FEE_PERCENTAGE) - MERCHANT_FIXED_FEE
        credit_wallet(peacelink.merchant_wallet, merchant_payout)
        credit_platform_profit(peacelink.item_price * MERCHANT_FEE_PERCENTAGE + MERCHANT_FIXED_FEE)
    else:
        # No DSP, merchant gets full item amount minus fees
        merchant_payout = peacelink.item_price * (1 - MERCHANT_FEE_PERCENTAGE) - MERCHANT_FIXED_FEE
        credit_wallet(peacelink.merchant_wallet, merchant_payout)
        
    log_admin_action(admin_id, 'release_to_seller', peacelink_id, {
        'merchant_payout': merchant_payout,
        'dsp_payout': dsp_payout if peacelink.dsp_assigned else 0,
        'platform_profit': calculated_profit
    })
```

---

### BUG-003: PeacePay Profit Not Updated on Buyer Cancel After DSP

**Issue:** When buyer cancels after DSP assigned, DSP is paid correctly but PeacePay profit ledger not updated with DSP fee.

**Current Behavior:**
- DSP wallet: Correctly updated
- PeacePay profit: NOT updated (missing 0.5% DSP fee)

**Fix Required:**
```python
# In buyer cancellation handler (after DSP assigned)
def process_buyer_cancel_after_dsp(peacelink):
    # Refund item amount only to buyer
    refund_to_buyer(peacelink.buyer_wallet, peacelink.item_price)
    
    # Pay DSP
    dsp_payout = peacelink.delivery_fee * (1 - DSP_FEE_PERCENTAGE)
    dsp_fee = peacelink.delivery_fee * DSP_FEE_PERCENTAGE
    
    credit_wallet(peacelink.dsp_wallet, dsp_payout)
    
    # CRITICAL: Update platform profit ledger
    credit_platform_profit(dsp_fee)  # <-- This was missing!
    
    log_transaction('buyer_cancel_after_dsp', {
        'buyer_refund': peacelink.item_price,
        'dsp_payout': dsp_payout,
        'platform_profit': dsp_fee
    })
```

---

### BUG-004: Double Fixed Fee in Advanced Payment Flow

**Issue:** System charges 3 EGP fixed fee twice - once on advance payout and once on final payout.

**Current Behavior:**
- Advance payout: charged 0.5% + 3 EGP
- Final payout: charged 0.5% + 3 EGP
- Total: 6 EGP fixed fee (should be 3 EGP)

**Expected Behavior:**
- Advance payout: 0.5% only (NO fixed fee)
- Final payout: 0.5% + 3 EGP
- Total: 3 EGP fixed fee

**Fix Required:**
```python
# Fee calculation for advanced payment
def calculate_advance_payout(item_price, advance_percentage):
    advance_amount = item_price * advance_percentage
    # Only percentage fee, NO fixed fee
    fee = advance_amount * MERCHANT_FEE_PERCENTAGE
    return advance_amount - fee

def calculate_final_payout(item_price, advance_percentage):
    remaining_amount = item_price * (1 - advance_percentage)
    # Percentage + fixed fee on final only
    fee = (remaining_amount * MERCHANT_FEE_PERCENTAGE) + MERCHANT_FIXED_FEE
    return remaining_amount - fee
```

---

### BUG-005: Cash-out Fee Not Deducted at Request Time

**Issue:** Fee shown to user but not actually deducted from wallet at request time.

**Current Behavior:**
- Only requested amount deducted
- Fee not deducted
- If rejected, only amount is refunded (fee was never taken)

**Expected Behavior:**
- Amount + Fee deducted at request time
- If approved, user receives requested amount
- If rejected, full amount + fee returned to wallet

**Fix Required:**
```python
def request_cashout(user_id, amount, method):
    fee = amount * CASHOUT_FEE_PERCENTAGE
    total_deduction = amount + fee
    
    wallet = get_wallet(user_id)
    
    if wallet.balance < total_deduction:
        raise InsufficientBalanceError(f"Need {total_deduction}, have {wallet.balance}")
    
    # Deduct FULL amount including fee NOW
    debit_wallet(user_id, total_deduction)
    
    # Create pending cashout record
    cashout = create_cashout_request(
        user_id=user_id,
        amount=amount,
        fee=fee,
        total_deducted=total_deduction,
        method=method,
        status='pending'
    )
    
    return cashout

def reject_cashout(cashout_id, admin_id, reason):
    cashout = get_cashout(cashout_id)
    
    # Refund FULL amount including fee
    credit_wallet(cashout.user_id, cashout.total_deducted)
    
    update_cashout_status(cashout_id, 'rejected', reason)
    
    # DO NOT credit platform profit on rejection
```

---

### BUG-006: Admin Release to Buyer - Incorrect Merchant Fee

**Issue:** When admin releases to buyer, system still deducts merchant fee even though merchant receives nothing.

**Fix Required:**
```python
def release_to_buyer(peacelink_id, admin_id):
    peacelink = get_peacelink(peacelink_id)
    
    # Refund buyer
    credit_wallet(peacelink.buyer_wallet, peacelink.item_price + peacelink.delivery_fee)
    
    if peacelink.dsp_assigned:
        # Pay DSP from escrow (not from merchant)
        dsp_payout = peacelink.delivery_fee * (1 - DSP_FEE_PERCENTAGE)
        credit_wallet(peacelink.dsp_wallet, dsp_payout)
        
        # Platform earns DSP fee ONLY (not merchant fee)
        credit_platform_profit(peacelink.delivery_fee * DSP_FEE_PERCENTAGE)
    
    # DO NOT deduct merchant fee - merchant receives nothing!
```

---

## High Priority Fixes

### Fix Merchant Cancel After DSP - Allow Action

**Issue:** Merchant has no cancellation option after DSP is assigned.

**Fix:** 
- Enable cancel button/API for merchant when status in ['dsp_assigned', 'in_transit']
- On cancel: Full refund to buyer, DSP paid from merchant wallet

```python
def can_merchant_cancel(peacelink, merchant_id):
    if peacelink.merchant_id != merchant_id:
        return False
    # Allow cancel before OTP is used
    return peacelink.status in ['created', 'approved', 'dsp_assigned', 'in_transit']
```

---

### Fix DSP Wallet Reassignment

**Issue:** Merchant cannot change DSP after assignment (before OTP).

**Fix:**
```python
def reassign_dsp(peacelink_id, merchant_id, new_dsp_wallet, reason=None):
    peacelink = get_peacelink(peacelink_id)
    
    # Validate
    if peacelink.merchant_id != merchant_id:
        raise PermissionError("Not authorized")
    if peacelink.status not in ['dsp_assigned', 'in_transit']:
        raise InvalidStateError("Cannot reassign DSP at this status")
    if peacelink.otp_used:
        raise InvalidStateError("Cannot reassign after OTP used")
    
    old_dsp = peacelink.dsp_wallet
    
    # Update DSP
    update_peacelink(peacelink_id, {
        'dsp_wallet': new_dsp_wallet,
        'dsp_reassigned_at': now(),
        'dsp_reassign_reason': reason
    })
    
    # Notify old and new DSP
    notify_dsp(old_dsp, 'removed_from_delivery', peacelink_id)
    notify_dsp(new_dsp_wallet, 'assigned_to_delivery', peacelink_id)
    
    # Log
    log_dsp_reassignment(peacelink_id, old_dsp, new_dsp_wallet, merchant_id, reason)
```

---

### Fix DSP Wallet Field - Disable Before Buyer Approval

**Issue:** Merchant can add DSP wallet before buyer approves.

**Fix:**
```python
def update_peacelink_dsp(peacelink_id, merchant_id, dsp_wallet):
    peacelink = get_peacelink(peacelink_id)
    
    # DSP can only be assigned AFTER buyer approval
    if peacelink.status == 'created':
        raise InvalidStateError("Cannot assign DSP before buyer approval")
    
    if peacelink.status not in ['approved']:
        raise InvalidStateError("DSP already assigned or invalid status")
    
    update_peacelink(peacelink_id, {
        'dsp_wallet': dsp_wallet,
        'status': 'dsp_assigned'
    })
```

---

## API Endpoint Changes Required

### 1. Cancel PeaceLink
```
POST /api/peacelinks/{id}/cancel
Authorization: Bearer {token}
Body: { "reason": "string" }

Response:
{
  "success": true,
  "refund": {
    "buyer_refund": 1100,
    "dsp_payout": 99.5,  // 0 if no DSP
    "merchant_deduction": 100,  // 0 if no DSP or buyer cancel
    "platform_profit": 0.5  // 0 if no DSP
  }
}
```

### 2. Reassign DSP
```
POST /api/peacelinks/{id}/reassign-dsp
Authorization: Bearer {token}
Body: { 
  "new_dsp_wallet": "01xxxxxxxxx",
  "reason": "string (optional)"
}

Response:
{
  "success": true,
  "old_dsp": "01yyyyyyyyy",
  "new_dsp": "01xxxxxxxxx"
}
```

### 3. Admin Release Actions
```
POST /api/admin/peacelinks/{id}/release
Authorization: Bearer {admin_token}
Body: {
  "release_to": "buyer" | "merchant",
  "notes": "string"
}

Response:
{
  "success": true,
  "breakdown": {
    "buyer_refund": 1000,
    "merchant_payout": 0,
    "dsp_payout": 99.5,
    "platform_profit": 0.5
  }
}
```

---

## Database Schema Updates

```sql
-- Add fee tracking to cashout table
ALTER TABLE cashouts ADD COLUMN fee DECIMAL(10,2) NOT NULL DEFAULT 0;
ALTER TABLE cashouts ADD COLUMN total_deducted DECIMAL(10,2) NOT NULL DEFAULT 0;

-- Add DSP reassignment tracking
ALTER TABLE peacelinks ADD COLUMN dsp_reassigned_at TIMESTAMP NULL;
ALTER TABLE peacelinks ADD COLUMN dsp_reassign_reason VARCHAR(255) NULL;
ALTER TABLE peacelinks ADD COLUMN previous_dsp_wallet VARCHAR(20) NULL;

-- Add profit breakdown to transactions
ALTER TABLE transactions ADD COLUMN merchant_fee DECIMAL(10,2) NULL;
ALTER TABLE transactions ADD COLUMN dsp_fee DECIMAL(10,2) NULL;
ALTER TABLE transactions ADD COLUMN platform_profit DECIMAL(10,2) NULL;
```

---

## Testing Checklist

- [ ] Buyer cancel before DSP: Full refund, no profit
- [ ] Buyer cancel after DSP: Item refund, DSP paid, profit updated
- [ ] Merchant cancel before DSP: Full refund, no profit
- [ ] Merchant cancel after DSP: Full refund, DSP paid from merchant
- [ ] Admin release to merchant: DSP paid, correct fees
- [ ] Admin release to buyer: DSP paid, no merchant fee
- [ ] Advanced payment: Fixed fee only on final release
- [ ] Cash-out: Fee deducted at request, refunded on rejection
- [ ] DSP reassignment: Works before OTP
- [ ] DSP assignment: Blocked before buyer approval
