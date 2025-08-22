@forelse ($escrowData as $item)
<tr>
    <td>{{ $item->escrow_id}}</td>
    <td>{{ substr($item->title,0,35)."..." }}</td>
    <td>{{ get_amount($item->amount, $item->escrow_currency) }}</td>
    <td><span class="{{ $item->string_status->class}}">{{ $item->string_status->value}}</span></td>
    <td>{{ $item->created_at->format('Y-m-d') }}</td>
    <td>
        @if ($item->buyer_or_seller_id == auth()->user()->id && $item->status == escrow_const()::APPROVAL_PENDING)
        <a href="{{ setRoute('user.escrow-action.paymentApprovalPending', encrypt($item->id))}}" class="btn btn--base bg--warning"><i class="las la-expand"></i></a>
        <a href="{{ setRoute('user.escrow-action.paymentCancel', encrypt($item->id))}}" class="btn btn--base bg--danger">{{__('Cancel')}}</a>
        @endif
        @if ($item->user_id == auth()->user()->id && $item->opposite_role == "buyer" && $item->status == escrow_const()::PAYMENT_WATTING)
        <a href="{{ setRoute('user.my-escrow.payment.crypto.address', $item->escrow_id)}}" class="btn btn--base bg--warning"><i class="las la-expand"></i></a>
        @endif
        @if ($item->user_id != auth()->user()->id && $item->opposite_role == "buyer" && $item->status == escrow_const()::PAYMENT_WATTING)
        <a href="{{ setRoute('user.escrow-action.payment.crypto.address', $item->escrow_id)}}" class="btn btn--base bg--warning"><i class="las la-expand"></i></a>
        @endif
        <a href="{{ setRoute('user.escrow-action.escrowConversation', encrypt($item->id))}}" class="btn btn--base chat-btn"><i class="las la-comment"></i>  
            @php
                $count = 0;
            @endphp 
            @foreach ($item->conversations as $conversation)
                @if ($conversation->seen == 0 && $conversation->sender != auth()->user()->id)
                    @php
                        $count++;
                    @endphp
                @endif
            @endforeach
            @if ($count > 0) 
            <span class="dot"></span>
            @endif
        </a>

        @if(auth()->user()->type == 'seller')
            <div class="btn-group share-buttons">
                <button onclick="copyToClipboard('{{ setRoute('user.escrow-action.paymentApprovalPending', encrypt($item->id)) }}')" class="btn btn--base bg--primary" title="Copy Link"><i class="las la-copy"></i></button>
                <a href="https://wa.me/201143536496/?text={{ urlencode(__('Please Pay Using This link').' '.setRoute('user.escrow-action.paymentApprovalPending', encrypt($item->id))) }}" target="_blank" class="btn btn--base bg--success" title="Share on WhatsApp"><i class="lab la-whatsapp"></i></a>
            </div>
        @endif
    </td>
</tr> 
@empty
<tr>
    <td colspan="10"><div class="alert alert-primary" style="margin-top: 37.5px; text-align:center">{{ __("No data found!") }}</div></td>
</tr>
@endforelse