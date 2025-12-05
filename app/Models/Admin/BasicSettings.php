<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BasicSettings extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'mail_config'               => 'object',
        'push_notification_config'  => 'object',
        'broadcast_config'          => 'object',
        'kyc_verification'          => 'integer',
        'incentive_balance_seller'  => 'decimal:8',
        'incentive_balance_buyer'   => 'decimal:8',
        'incentive_balance_delivery'=> 'decimal:8',
    ];


    public function mailConfig() {
        
    }
}
