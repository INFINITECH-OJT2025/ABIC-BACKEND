<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class Voucher extends Model
{
    use HasFactory;

    protected $fillable = [
        'paid_to',
        'voucher_no',
        'date',
        'project_details',
        'owner_client',
        'purpose',
        'note',
        'total_amount',
        'received_by_name',
        'received_by_signature_url',
        'received_by_date',
        'approved_by_name',
        'approved_by_signature_url',
        'approved_by_date',
        'status',
    ];

    protected $casts = [
        'date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $hidden = [
        'received_by_signature_url',
        'approved_by_signature_url',    
    ];
}
