<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageTracking extends Model
{
    protected $table = 'usage_tracking';

    protected $fillable = [
        'user_id',
        'feature_name',
        'usage_count',
        'period_start',
        'period_end',
    ];

    protected function casts(): array
    {
        return [
            'usage_count' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
