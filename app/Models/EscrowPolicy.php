<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EscrowPolicy extends Model
{
    use HasFactory;
    protected $fillable = [
        'escrow_id',
        'policy_id',
        'fee'
    ];
}
