<?php

namespace App\Events;

use App\Models\AuditRun;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AuditStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public AuditRun $auditRun)
    {
        //
    }

    public function broadcastOn(): Channel
    {
        return new Channel('audit.user.'.$this->auditRun->user_id);
    }

    public function broadcastAs(): string
    {
        return 'audit.status.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'audit' => [
                'id' => $this->auditRun->id,
                'status' => $this->auditRun->status,
                'business_name' => $this->auditRun->business_name,
                'website' => $this->auditRun->website,
                'location' => $this->auditRun->location,
                'reputation_score' => $this->auditRun->reputation_score,
                'scan_date' => $this->auditRun->scan_date?->toISOString(),
                'error_code' => $this->auditRun->error_code,
                'error_message' => $this->auditRun->error_message,
                'updated_at' => $this->auditRun->updated_at?->toISOString(),
            ],
            'message' => $this->messageForStatus($this->auditRun->status),
        ];
    }

    private function messageForStatus(string $status): string
    {
        return match ($status) {
            'success' => 'Your audit is complete.',
            'error' => 'Your audit failed. Please try again.',
            'selection_required' => 'Audit requires business selection to continue.',
            default => 'Your audit status has been updated.',
        };
    }
}

