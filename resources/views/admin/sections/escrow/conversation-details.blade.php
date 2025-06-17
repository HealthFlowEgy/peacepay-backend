<style>
    
</style>
<div class="support-profile-wrapper">
    <div class="support-profile-header escrow-profile-header">
        <div class="escrow-details-btn-wrapper">
            <!-- Original Buttons -->
            <button type="button" class="btn--base releaseToBuyer">Release to Buyer</button>
            <button type="button" class="btn--base releaseToSeller">Release to Seller</button>
            
            <!-- Release Options with Checkboxes -->
            <div class="release-options-wrapper mb-3 mt-3">
                <h6 class="mb-2">{{ __("Multiple Release Options") }}</h6>
                <div class="form-check-group">
                    <div class="form-check">
                        <input class="form-check-input release-option" type="checkbox" value="buyer" id="releaseToBuyerCheck">
                        <label class="form-check-label" for="releaseToBuyerCheck">
                            {{ __("Release to Buyer") }}
                        </label>
                        <!-- Buyer Amount Input -->
                        <div class="amount-input-wrapper mt-2" id="buyerAmountWrapper" style="display: none;">
                            <div class="input-group">
                                <input type="number" class="form-control" id="buyerAmount" placeholder="0.00" step="0.01" min="0">
                                <span class="input-group-text">{{ $escrows->escrow_currency }}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-check mt-2">
                        <input class="form-check-input release-option" type="checkbox" value="seller" id="releaseToSellerCheck">
                        <label class="form-check-label" for="releaseToSellerCheck">
                            {{ __("Release to Seller") }}
                        </label>
                        <!-- Seller Amount Input -->
                        <div class="amount-input-wrapper mt-2" id="sellerAmountWrapper" style="display: none;">
                            <div class="input-group">
                                <input type="number" class="form-control" id="sellerAmount" placeholder="0.00" step="0.01" min="0">
                                <span class="input-group-text">{{ $escrows->escrow_currency }}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-check mt-2">
                        <input class="form-check-input release-option" type="checkbox" value="delivery" id="releaseToDeliveryCheck">
                        <label class="form-check-label" for="releaseToDeliveryCheck">
                            {{ __("Release to Delivery") }}
                        </label>
                        <!-- Delivery Amount Input -->
                        <div class="amount-input-wrapper mt-2" id="deliveryAmountWrapper" style="display: none;">
                            <div class="input-group">
                                <input type="number" class="form-control" id="deliveryAmount" placeholder="0.00" step="0.01" min="0">
                                <span class="input-group-text">{{ $escrows->escrow_currency }}</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Confirm Button (Initially Hidden) -->
                <div class="confirm-btn-wrapper mt-3" style="display: none;">
                    <button type="button" class="btn btn-success" id="confirmMultipleRelease">
                        {{ __("Confirm Multiple Release") }}
                    </button>
                </div>
            </div>
        </div>
        <div class="chat-cross-btn">
            <i class="las la-times"></i>
        </div>
    </div>
    <div class="support-profile-body">
        <div class="support-profile-box">
            <h5 class="title">{{ __("Escrow Details") }}</h5>
            <ul class="support-profile-list">
                <li>{{ __("Title") }} : <span>{{ $escrows->title }}</span></li> 
                <li>{{ __("Role") }}Payment Details : <span class="text-capitalize">{{ $escrows->role }}</span></li>
                <li>{{ __("Created By") }} : <span class="text-capitalize">{{ $escrows->user->username }}</span></li>
                <li>{{ __("Product Type") }} : <span>{{ $escrows->escrowCategory->name }}</span></li>
                <li>{{ __("Total Price") }} : <span>{{ get_amount($escrows->amount,$escrows->escrow_currency) }}</span></li>
                <li>{{ __("Charge Payer") }} : <span>{{ $escrows->string_who_will_pay->value }}</span></li>
                <li>{{ __("Status") }} : <span class="{{ $escrows->string_status->class}}">{{ $escrows->string_status->value}}</span></li>
                @foreach ($escrows->file ?? [] as $key => $item)
                <li>{{ __("Attachments") }} - {{ $key + 1 }} : 
                    <span class="text--danger">
                        <a href="{{ files_asset_path('escrow-temp-file') . "/" . $item->attachment }}" target="_blanck">
                            {{ Str::words(json_decode($item->attachment_info)->original_base_name ?? "", 5, '...' . json_decode($item->attachment_info)->extension ?? "" ) }}
                        </a>
                    </span>
                </li>
                @endforeach
                <li>{{ __("Remarks") }} : <span class="mb-3">{{ $escrows->remark }}</span></li>  
            </ul>
        </div>
        <div class="support-profile-box">
            <h5 class="title">{{ __("Payment Details") }}</h5>
            <ul class="support-profile-list">
                <li>{{ __("Fees & Charge") }} : <span>{{ get_amount($escrows->escrowDetails->fee,$escrows->escrow_currency) }}</span></li>
                <li>{{ __("Seller Amount") }} : <span>{{ get_amount($escrows->escrowDetails->seller_get,$escrows->escrow_currency) }}</span></li>
                @if ($escrows->payment_type == escrow_const()::GATEWAY)
                <li>{{ __("Pay with") }} : <span>{{ @$escrows->paymentGatewayCurrency->name }}</span></li>  
                <li>{{ __("Exchange Rate") }} : <span>{{ "1 ".$escrows->escrow_currency." = ".get_amount($escrows->escrowDetails->gateway_exchange_rate,$escrows->paymentGatewayCurrency->currency_code) }}</span></li>  
                <li>{{ __("Buyer Paid") }} : <span>{{ get_amount($escrows->escrowDetails->buyer_pay,$escrows->paymentGatewayCurrency->currency_code) }}</span></li>  
                @endif
                @if ($escrows->payment_type == escrow_const()::MY_WALLET)
                <li>{{ __("Pay with") }} : <span>{{ "My Wallet" }}</span></li>  
                <li>{{ __("Exchange Rate") }} : <span>{{ "1 ".$escrows->escrow_currency." = 1 ".$escrows->escrow_currency }}</span></li>  
                <li>{{ __("Buyer Paid") }} : <span>{{ get_amount($escrows->escrowDetails->buyer_pay, $escrows->escrow_currency) }}</span></li>  
                @endif
            </ul>
        </div>
    </div>
</div> 

@push('script')
<script>
    // Original button functions
    $(".releaseToBuyer").click(function(){
        var actionRoute =  "{{ setRoute('admin.escrow.release.payment','buyer') }}";
        var target      = "{{ $escrows->id }}";
        var message     = `Are you sure to <strong>release this payment to the buyer</strong>?`;

        openAlertModal(actionRoute,target,message,"Confirm","POST");
    });
    
    $(".releaseToSeller").click(function(){
        var actionRoute =  "{{ setRoute('admin.escrow.release.payment','seller') }}";
        var target      = "{{ $escrows->id }}";
        var message     = `Are you sure to <strong>release this payment to the seller</strong>?`;

        openAlertModal(actionRoute,target,message,"Confirm","POST");
    });

    $(document).ready(function() {
        // Handle checkbox selection for multiple options
        $('.release-option').on('change', function() {
            var checkboxValue = $(this).val();
            var amountWrapper = $('#' + checkboxValue + 'AmountWrapper');
            
            if ($(this).is(':checked')) {
                // Show the corresponding amount input
                amountWrapper.show();
            } else {
                // Hide the corresponding amount input and clear value
                amountWrapper.hide();
                $('#' + checkboxValue + 'Amount').val('');
            }
            
            // Show/hide confirm button based on any checkbox being checked
            if ($('.release-option:checked').length > 0) {
                $('.confirm-btn-wrapper').show();
            } else {
                $('.confirm-btn-wrapper').hide();
            }
        });

        // Handle confirm multiple release button
        $('#confirmMultipleRelease').click(function() {
            var selectedOptions = [];
            var totalAmount = 0;
            var isValid = true;
            
            // Collect all checked options and their amounts
            $('.release-option:checked').each(function() {
                var optionValue = $(this).val();
                var amount = parseFloat($('#' + optionValue + 'Amount').val());
                
                if (!amount || amount <= 0) {
                    alert('Please enter a valid amount for ' + optionValue + '.');
                    isValid = false;
                    return false;
                }
                
                selectedOptions.push({
                    type: optionValue,
                    amount: amount
                });
                totalAmount += amount;
            });
            
            if (!isValid) return;
            
            if (selectedOptions.length === 0) {
                alert('Please select at least one release option.');
                return;
            }
            
            // Create summary message
            var summaryParts = [];
            selectedOptions.forEach(function(option) {
                summaryParts.push(`${option.amount} {{ $escrows->escrow_currency }} to ${option.type}`);
            });
            
            
            var actionRoute = "{{ setRoute('admin.escrow.release.payment.custom') }}";
            var target = "{{ $escrows->id }}";
            var escrow_amount = {{$escrows->amount}};
            var data = {
                escrow_id: target,
                releases: selectedOptions,
                total_amount: totalAmount,
                escrow_amount: escrow_amount
            };
            
            var message = `Are you sure to release:<br><strong>${summaryParts.join('<br>')}</strong>
            <br>Total: <strong>${totalAmount} {{ $escrows->escrow_currency }}</strong>
            <br>Peacepay Will get: <strong>${escrow_amount - totalAmount} {{ $escrows->escrow_currency }}</strong>`;


            openAlertModal(actionRoute, target, message, "Confirm", "POST",data);
        });
    });
</script>
@endpush