<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditQueueLimit extends Model
{
    protected $table = 'audit_queue_limits';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'user_id',
        'concurrent_audits_allowed',
        'current_running_count',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'concurrent_audits_allowed' => 'integer',
            'current_running_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
