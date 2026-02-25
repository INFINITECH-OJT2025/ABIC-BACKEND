<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'voucher_no',
        'voucher_date',
        'trans_method',
        'trans_type',
        'from_owner_id',
        'to_owner_id',
        'unit_id',
        'amount',
        'fund_reference',
        'particulars',
        'transfer_group_id',
        'person_in_charge',
        'status',
        'is_posted',
        'posted_at',
        'created_by',
    ];

    protected $casts = [
        'voucher_date' => 'date',
        'amount' => 'decimal:2',
        'is_posted' => 'boolean',
        'posted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the instruments for the transaction.
     */
    public function instruments(): HasMany
    {
        return $this->hasMany(TransactionInstrument::class);
    }

    /**
     * Get the attachments for the transaction.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TransactionAttachment::class);
    }

    /**
     * Get the from owner.
     */
    public function fromOwner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'from_owner_id');
    }

    /**
     * Get the to owner.
     */
    public function toOwner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'to_owner_id');
    }

    /**
     * Get the unit.
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    /**
     * Get the user who created the transaction.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the ledger entries for this transaction (one per owner).
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(OwnerLedgerEntry::class);
    }
}
