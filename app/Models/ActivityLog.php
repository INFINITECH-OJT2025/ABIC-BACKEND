<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    use HasFactory;

    protected $table = 'activity_logs';

    protected $fillable = [
        'activity_type',
        'action',
        'status',
        'title',
        'description',
        'user_id',
        'user_name',
        'user_email',
        'target_id',
        'target_type',
        'metadata',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    public $timestamps = false; // We only use created_at, not updated_at

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Get the user who performed the activity.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the target model (polymorphic).
     */
    public function target()
    {
        return $this->morphTo('target', 'target_type', 'target_id');
    }
}
