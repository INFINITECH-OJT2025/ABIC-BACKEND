<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankContactChannel extends Model
{
    use HasFactory;

    protected $fillable = [
        'contact_id',
        'channel_type',
        'value',
        'label',
    ];

    public $timestamps = false;

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Get the contact that owns the channel
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(BankContact::class, 'contact_id');
    }
}
