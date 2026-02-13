<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditRun extends Model
{
    protected $fillable = [
        'user_id',
        'status',
        'business_name',
        'website',
        'phone',
        'location',
        'industry',
        'place_id',
        'skip_places',
        'reputation_score',
        'scan_date',
        'error_code',
        'error_message',
        'ip_address',
        'user_agent',
        'request_payload',
        'response_payload',
    ];

    protected function casts(): array
    {
        return [
            'skip_places' => 'boolean',
            'reputation_score' => 'integer',
            'scan_date' => 'datetime',
            'request_payload' => 'array',
            'response_payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

