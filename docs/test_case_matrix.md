# PeacePay Test Case Matrix
## Mapped to Business Rules

### Test Categories
1. SPH (Secure Payment Hold) Creation & Approval
2. DSP Assignment & Reassignment
3. OTP & Delivery Confirmation
4. Cancellation Scenarios
5. Fee Calculations
6. Dispute Resolution
7. Cash-out Processing
8. Edge Cases & Invariants

---

## Category 1: SPH Creation & Approval

| TC-ID | Scenario | Preconditions | Steps | Expected Result | Business Rule |
|-------|----------|---------------|-------|-----------------|---------------|
| TC-001 | Create basic PeaceLink | Merchant logged in | 1. Enter buyer phone 2. Enter item details 3. Set delivery fee 4. Submit | PeaceLink created, SMS sent to buyer | Rule 27 |
| TC-002 | Create PeaceLink with advance payment | Merchant with advance enabled | 1. Create PeaceLink 2. Set 50% advance | PeaceLink shows advance amount | Rule 15 |
| TC-003 | Buyer approves PeaceLink | PeaceLink pending, buyer has balance | 1. Open link 2. Review details 3. Enter PIN 4. Confirm | SPH active, buyer debited, advance paid to merchant | Rule 15, 26 |
| TC-004 | Buyer approves - insufficient balance | PeaceLink pending, buyer balance < total | 1. Open link 2. Attempt approval | Blocked with "Insufficient balance" | Rule 35 |
| TC-005 | PeaceLink expires without approval | PeaceLink pending for 24h | Wait 24 hours | Status = Expired, no charges | Rule 27 |
| TC-006 | Unauthorized user tries to access PeaceLink | PeaceLink for phone A, user with phone B | 1. Try to open link | Access denied, "Not authorized" | Security |
| TC-007 | Duplicate approval attempt | PeaceLink already approved | 1. Try to approve again | Rejected, idempotent response | Rule 30 |

---

## Category 2: DSP Assignment & Reassignment

| TC-ID | Scenario | Preconditions | Steps | Expected Result | Business Rule |
|-------|----------|---------------|-------|-----------------|---------------|
| TC-010 | Assign DSP to active SPH | SPH active | 1. Enter DSP wallet 2. Validate 3. Assign | DSP assigned, OTP generated, SMS sent | Rule 19 |
| TC-011 | Assign invalid DSP wallet | SPH active | 1. Enter non-existent wallet | Error: DSP wallet not found | Validation |
| TC-012 | DSP wallet field visible before approval | PeaceLink pending | View merchant screen | DSP field should be HIDDEN | Bug Fix |
| TC-013 | Reassign DSP (before OTP) | DSP assigned, OTP not used | 1. Click Change DSP 2. Enter reason 3. Enter new wallet | New DSP assigned, old DSP removed | Feature |
| TC-014 | Reassign DSP (second attempt) | Already reassigned once | 1. Try to reassign again | Blocked: Max reassignments reached | Feature |
| TC-015 | Reassign DSP (after OTP) | OTP already used | 1. Try to reassign | Blocked: Transaction complete | Rule 7 |

---

## Category 3: OTP & Delivery Confirmation

| TC-ID | Scenario | Preconditions | Steps | Expected Result | Business Rule |
|-------|----------|---------------|-------|-----------------|---------------|
| TC-020 | OTP visible to buyer (after DSP assigned) | DSP assigned | View buyer PeaceLink detail | OTP displayed | Rule 47 |
| TC-021 | OTP hidden from buyer (before DSP) | SPH active, no DSP | View buyer PeaceLink detail | OTP section NOT visible | Rule 47 |
| TC-022 | Valid OTP entry | DSP assigned | 1. Enter correct 6-digit OTP | Delivery confirmed, funds released | Rule 7 |
| TC-023 | Invalid OTP entry | DSP assigned | 1. Enter wrong OTP | Error, attempts incremented | Rule 29 |
| TC-024 | OTP expires | DSP assigned, OTP not used for 24h | Wait for expiry | Delivery blocked, new OTP generated | Rule 29 |
| TC-025 | Duplicate OTP entry | OTP already used | 1. Enter same OTP again | Rejected, single release only | Rule 31 |
| TC-026 | DSP enters OTP | OTP generated | 1. DSP views delivery 2. Asks buyer for OTP 3. Enters OTP | Delivery confirmed | Rule 8 |
| TC-027 | Courier incentive on OTP | Driver enters OTP | Enter valid OTP | Incentive credited to OTP-entering user | Rule 8 |

---

## Category 4: Cancellation Scenarios

| TC-ID | Scenario | Preconditions | Steps | Expected Result | Business Rule |
|-------|----------|---------------|-------|-----------------|---------------|
| TC-030 | Buyer cancels before DSP | SPH active, no DSP | 1. Buyer clicks Cancel | Full refund (item + delivery), no fees | Rule 0 |
| TC-031 | Buyer cancels after DSP | DSP assigned | 1. Buyer clicks Cancel | Item refund only, buyer pays DSP fee | Rule 1 |
| TC-032 | Buyer cancels after OTP | Delivered | 1. Try to cancel | Not allowed, dispute only | Rule 2 |
| TC-033 | Merchant cancels before DSP | SPH active, no DSP | 1. Merchant clicks Cancel | Full refund, merchant fault | Rule 3 |
| TC-034 | Merchant cancels after DSP | DSP assigned | 1. Merchant clicks Cancel | Full refund, merchant pays DSP | Rule 4 |
| TC-035 | Merchant cancels after OTP | Delivered | 1. Try to cancel | Not allowed, use refund flow | Rule 5 |
| TC-036 | DSP cancels assigned delivery | DSP assigned | 1. DSP clicks Cancel Delivery | Removed from order, awaiting reassignment | Feature |
| TC-037 | Merchant cancel button visible after DSP | DSP assigned | View merchant PeaceLink detail | Cancel button MUST be visible | Bug Fix |
| TC-038 | Buyer cancel shows correct label | Pre-DSP state | View buyer detail | Button says "Cancel Order" NOT "Return Item" | Bug Fix |

---

## Category 5: Fee Calculations

| TC-ID | Scenario | Preconditions | Steps | Expected Result | Business Rule |
|-------|----------|---------------|-------|-----------------|---------------|
| TC-050 | Merchant fee - standard delivery | No advance payment | Complete delivery | Merchant receives: item - 0.5% - 2 EGP | Fee config |
| TC-051 | Merchant fee - advance payment | 50% advance | Complete delivery | Advance: -0.5% only, Final: -0.5% -2 EGP | Rule 15 |
| TC-052 | Fixed fee charged once only | 50% advance | Complete delivery | 2 EGP charged ONLY on final, NOT on advance | Bug Fix |
| TC-053 | DSP fee calculation | Standard delivery | Complete delivery | DSP receives: delivery_fee - 0.5% | Fee config |
| TC-054 | DSP fee on buyer cancel after assignment | Buyer cancels after DSP | Cancel flow | DSP still receives delivery_fee - 0.5% | Rule 1 |
| TC-055 | DSP fee on merchant cancel after assignment | Merchant cancels after DSP | Cancel flow | DSP paid from merchant wallet | Rule 4 |
| TC-056 | Cash-out fee deducted at request | User requests cash-out | Submit request | Fee deducted immediately, not on approval | Bug Fix |
| TC-057 | Cash-out fee refunded on rejection | Admin rejects cash-out | Reject request | Fee returned to user wallet | Bug Fix |
| TC-058 | PeacePay profit on advance | Advance paid | Check profit ledger | Profit updated IMMEDIATELY | Bug Fix |
| TC-059 | PeacePay profit on final | Final release | Check profit ledger | Profit updated IMMEDIATELY | Bug Fix |

---

## Category 6: Dispute Resolution

| TC-ID | Scenario | Preconditions | Steps | Expected Result | Business Rule |
|-------|----------|---------------|-------|-----------------|---------------|
| TC-060 | Buyer opens dispute | After OTP | 1. Click Report Issue 2. Submit reason | Dispute created, funds locked | Rule 12 |
| TC-061 | Admin releases to buyer | Dispute open | 1. Admin clicks Release to Buyer | Buyer gets item refund, DSP keeps fee | Rule 17 |
| TC-062 | Admin releases to merchant | Dispute open | 1. Admin clicks Release to Merchant | Merchant gets item - fees, DSP paid | Rule 17 |
| TC-063 | Admin release - DSP always paid | DSP was assigned | Any admin resolution | DSP fee paid regardless of outcome | Rule 19 |
| TC-064 | Admin release - no merchant fee if no payout | Release to buyer | Check fees | Merchant fee NOT charged (merchant gets nothing) | Bug Fix |
| TC-065 | Admin release shows breakdown | Any release action | View confirmation | Shows: Buyer X, Merchant X, DSP X, Platform X | Feature |

---

## Category 7: Cash-out Processing

| TC-ID | Scenario | Preconditions | Steps | Expected Result | Business Rule |
|-------|----------|---------------|-------|-----------------|---------------|
| TC-070 | Merchant cash-out request | Balance sufficient | 1. Enter amount 2. Confirm | Amount + fee deducted, status = pending | Rule 23 |
| TC-071 | Cash-out - insufficient for fee | Balance = amount, not amount + fee | Submit request | Blocked: Insufficient balance | Bug Fix |
| TC-072 | Admin approves cash-out | Request pending | 1. Admin clicks Approve | External transfer initiated | Rule 23 |
| TC-073 | Admin rejects cash-out | Request pending | 1. Admin clicks Reject | Fee refunded to user | Bug Fix |
| TC-074 | DSP cash-out request | DSP with balance | Same as merchant | Same behavior | Rule 23 |

---

## Category 8: Edge Cases & Invariants

| TC-ID | Scenario | Preconditions | Steps | Expected Result | Business Rule |
|-------|----------|---------------|-------|-----------------|---------------|
| TC-080 | Ledger balance invariant | Any completed transaction | Sum all ledger entries | Buyer debit = Merchant + DSP + Platform | Rule 26 |
| TC-081 | Concurrent approval attempts | Two devices, same buyer | Both try to approve | Only one succeeds, other rejected | Rule 36 |
| TC-082 | Balance changes after approval | SPH active, buyer spends | Buyer makes other transaction | SPH unaffected, held balance isolated | Rule 36 |
| TC-083 | Duplicate cancel request | Cancel initiated | 1. Click cancel twice | Single refund only | Rule 30 |
| TC-084 | PSP webhook retry | Webhook fails | PSP retries | No duplicate effect | Rule 33 |
| TC-085 | Fee change during transaction | Admin changes fees | Change fees | Original fees frozen for transaction | Rule 21 |
| TC-086 | Max delivery time exceeded | Delivery deadline passed | System timeout | Buyer can cancel with full refund | Rule 28 |
| TC-087 | Account freeze during transaction | Admin freezes account | Freeze during SPH | No auto refund, compliance hold | Rule 24 |

---

## Test Execution Checklist

### Pre-Release Mandatory Tests
- [ ] TC-001 to TC-007: Basic PeaceLink flow
- [ ] TC-020 to TC-026: OTP flow
- [ ] TC-030 to TC-038: All cancellation scenarios
- [ ] TC-050 to TC-059: Fee calculations
- [ ] TC-080: Ledger invariant verification

### Bug Fix Verification Tests
- [ ] TC-021: OTP hidden before DSP
- [ ] TC-036: DSP cancel option
- [ ] TC-037: Merchant cancel after DSP
- [ ] TC-038: Correct button labels
- [ ] TC-052: Fixed fee once only
- [ ] TC-056: Cash-out fee at request
- [ ] TC-058, TC-059: Immediate profit ledger update
- [ ] TC-064: No merchant fee on release to buyer

### Regression Tests (After Each Deploy)
- [ ] TC-003: Buyer approval flow
- [ ] TC-022: OTP delivery confirmation
- [ ] TC-051: Advance payment calculation
- [ ] TC-080: Ledger invariant

---

## Automated Test Coverage Requirements

| Category | Unit Tests | Integration Tests | E2E Tests |
|----------|------------|-------------------|-----------|
| SPH Creation | 90% | 80% | 100% (happy path) |
| DSP Assignment | 90% | 80% | 100% (happy path) |
| OTP Flow | 95% | 90% | 100% (happy path) |
| Cancellations | 100% | 100% | All scenarios |
| Fee Calculations | 100% | 100% | Critical paths |
| Disputes | 80% | 70% | Admin flows |
| Cash-out | 90% | 80% | Happy path |
| Edge Cases | 100% | 80% | Selected |

---

## Notes

1. **All fee-related tests must verify ledger entries** - Not just wallet balances
2. **Cancellation tests must cover all state transitions** - Matrix of (who cancels × when)
3. **Admin actions must be tested with audit log verification**
4. **Arabic content tests required for all user-facing screens**
5. **RTL layout tests required for mobile app screens**
