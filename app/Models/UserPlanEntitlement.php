<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPlanEntitlement extends Model
{
    protected $fillable = [
        'user_id',
        'plan_id',
        'status',
        'billing_interval',
        'source',
        'starts_at',
        'expires_at',
        'stripe_customer_id',
        'stripe_subscription_id',
        'stripe_checkout_session_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
