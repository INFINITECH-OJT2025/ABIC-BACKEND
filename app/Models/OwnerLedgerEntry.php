<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OwnerLedgerEntry extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'owner_id',
        'transaction_id',
        'voucher_no',
        'voucher_date',
        'instrument_no',
        'debit',
        'credit',
        'running_balance',
        'unit_id',
        'particulars',
        'transfer_group_id',
    ];

    protected $casts = [
        'voucher_date' => 'date',
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'running_balance' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
