<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankContact extends Model
{
    use HasFactory;

    protected $fillable = [
        'bank_id',
        'branch_name',
        'contact_person',
        'position',
        'notes',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the bank that owns the contact
     */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    /**
     * Get the channels for the contact
     */
    public function channels(): HasMany
    {
        return $this->hasMany(BankContactChannel::class, 'contact_id');
    }
}
