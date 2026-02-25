<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Owner extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_code',
        'owner_type',
        'name',
        'description',
        'email',
        'phone',
        'address',
        'status',
        'is_system',
        'created_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'is_system' => 'boolean',
    ];

    /**
     * Get the ledger entries for this owner.
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(OwnerLedgerEntry::class);
    }

    /**
     * Get the user who created this owner.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
