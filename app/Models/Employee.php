<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

class Employee extends Model
{
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected $fillable = [
        'user_id',
        'email',
        'password',
        'status',
        'position',
        'onboarding_date',
        'access_level',
        'equipment_issued',
        'training_completed',
        'onboarding_notes',
        'date_hired',
        'last_name',
        'first_name',
        'middle_name',
        'suffix',
        'birthday',
        'birthplace',
        'civil_status',
        'gender',
        'sss_number',
        'philhealth_number',
        'pagibig_number',
        'tin_number',
        'mlast_name',
        'mfirst_name',
        'mmiddle_name',
        'msuffix',
        'flast_name',
        'ffirst_name',
        'fmiddle_name',
        'fsuffix',
        'mobile_number',
        'house_number',
        'street',
        'village',
        'subdivision',
        'barangay',
        'region',
        'province',
        'city_municipality',
        'zip_code',
        'email_address',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'training_completed' => 'boolean',
            'onboarding_date' => 'date',
            'date_hired' => 'date',
            'birthday' => 'date',
        ];
    }

    public function setPasswordAttribute($value): void
    {
        $this->attributes['password'] = Hash::make($value);
    }
}
