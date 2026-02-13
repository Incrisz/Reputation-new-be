<?php

namespace App\Mail;

use App\Models\AuditRun;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AuditCompletedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public AuditRun $auditRun)
    {
        //
    }

    public function build(): self
    {
        $businessName = $this->auditRun->business_name ?: 'your business';
        $isSuccess = $this->auditRun->status === 'success';

        $subject = $isSuccess
            ? "Audit complete for {$businessName}"
            : "Audit finished for {$businessName}";

        return $this
            ->subject($subject)
            ->view('emails.audit-completed', [
                'auditRun' => $this->auditRun,
                'frontendUrl' => rtrim((string) config('app.frontend_url', ''), '/'),
            ]);
    }
}
