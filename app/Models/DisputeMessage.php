<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Dispute Message Model
 * Stores messages in dispute conversations
 * Based on Re-Engineering Specification v2.0
 */
class DisputeMessage extends Model
{
    use HasFactory;

    protected $table = 'dispute_messages';
    
    public $timestamps = false;

    protected $fillable = [
        'dispute_id',
        'sender_id',
        'message',
        'attachments',
        'is_admin_only',
    ];

    protected $casts = [
        'attachments' => 'array',
        'is_admin_only' => 'boolean',
        'created_at' => 'datetime',
    ];

    /**
     * Get the dispute
     */
    public function dispute()
    {
        return $this->belongsTo(Dispute::class);
    }

    /**
     * Get the sender
     */
    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * Scope for public messages (visible to all parties)
     */
    public function scopePublic($query)
    {
        return $query->where('is_admin_only', false);
    }

    /**
     * Scope for admin-only messages
     */
    public function scopeAdminOnly($query)
    {
        return $query->where('is_admin_only', true);
    }
}
