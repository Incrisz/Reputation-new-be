<?php

namespace App\Jobs;

use App\Mail\AuditCompletedMail;
use App\Models\AuditRun;
use App\Services\BusinessVerificationService;
use App\Services\ReputationScanService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class RunAuditScanJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 300;

    public function __construct(public int $auditRunId)
    {
        //
    }

    public function handle(
        BusinessVerificationService $verificationService,
        ReputationScanService $scanService
    ): void {
        $auditRun = AuditRun::query()->find($this->auditRunId);
        if (!$auditRun) {
            return;
        }

        if (in_array($auditRun->status, ['success', 'error', 'selection_required'], true)) {
            return;
        }

        $requestPayload = is_array($auditRun->request_payload)
            ? $auditRun->request_payload
            : [];

        $auditRun->status = 'processing';
        $auditRun->save();

        $verification = $verificationService->verify($requestPayload);
        if (!$verification['success']) {
            $auditRun->fill([
                'status' => 'error',
                'error_code' => $verification['error_code'] ?? 'VERIFICATION_FAILED',
                'error_message' => $verification['message'] ?? 'Business verification failed',
                'response_payload' => [
                    'status' => 'error',
                    'code' => $verification['error_code'] ?? 'VERIFICATION_FAILED',
                    'message' => $verification['message'] ?? 'Business verification failed',
                    'details' => $verification['details'] ?? null,
                ],
            ])->save();

            $this->sendCompletionEmail($auditRun);
            return;
        }

        $scanResult = $scanService->scan($verification['business_data']);
        if (!$scanResult['success']) {
            $auditRun->fill([
                'status' => 'error',
                'business_name' => $verification['business_data']['business_name'] ?? $auditRun->business_name,
                'website' => $verification['business_data']['verified_website'] ?? $auditRun->website,
                'location' => $verification['business_data']['verified_location'] ?? $auditRun->location,
                'phone' => $verification['business_data']['verified_phone'] ?? $auditRun->phone,
                'error_code' => $scanResult['error_code'] ?? 'ANALYSIS_ERROR',
                'error_message' => $scanResult['message'] ?? 'An error occurred during scan',
                'response_payload' => [
                    'status' => 'error',
                    'code' => $scanResult['error_code'] ?? 'ANALYSIS_ERROR',
                    'message' => $scanResult['message'] ?? 'An error occurred during scan',
                    'details' => $scanResult['details'] ?? null,
                ],
            ])->save();

            $this->sendCompletionEmail($auditRun);
            return;
        }

        $successResponse = [
            'status' => 'success',
            'business_name' => $scanResult['business_name'],
            'verified_website' => $scanResult['verified_website'] ?? null,
            'verified_location' => $scanResult['verified_location'] ?? null,
            'verified_phone' => $scanResult['verified_phone'] ?? null,
            'scan_date' => $scanResult['scan_date'],
            'results' => $scanResult['results'],
        ];

        $scanDate = $scanResult['scan_date'] ?? null;
        $reputationScore = $scanResult['results']['reputation_score'] ?? null;

        $auditRun->fill([
            'status' => 'success',
            'business_name' => $scanResult['business_name'] ?? $auditRun->business_name,
            'website' => $scanResult['verified_website'] ?? $auditRun->website,
            'location' => $scanResult['verified_location'] ?? $auditRun->location,
            'phone' => $scanResult['verified_phone'] ?? $auditRun->phone,
            'scan_date' => $scanDate,
            'reputation_score' => is_numeric($reputationScore) ? (int) $reputationScore : null,
            'error_code' => null,
            'error_message' => null,
            'response_payload' => $successResponse,
        ])->save();

        $this->sendCompletionEmail($auditRun);
    }

    public function failed(\Throwable $e): void
    {
        $auditRun = AuditRun::query()->find($this->auditRunId);
        if (!$auditRun) {
            return;
        }

        $auditRun->fill([
            'status' => 'error',
            'error_code' => 'QUEUE_JOB_FAILED',
            'error_message' => 'Audit processing job failed unexpectedly.',
            'response_payload' => [
                'status' => 'error',
                'code' => 'QUEUE_JOB_FAILED',
                'message' => 'Audit processing job failed unexpectedly.',
            ],
        ])->save();

        $this->sendCompletionEmail($auditRun);
    }

    private function sendCompletionEmail(AuditRun $auditRun): void
    {
        if (!$auditRun->user_id) {
            \Log::warning('Skipping audit completion email: audit has no user.', [
                'audit_run_id' => $auditRun->id,
            ]);
            return;
        }

        $user = $auditRun->user()->first();
        if (!$user || empty($user->email)) {
            \Log::warning('Skipping audit completion email: user email missing.', [
                'audit_run_id' => $auditRun->id,
                'user_id' => $auditRun->user_id,
            ]);
            return;
        }

        try {
            Mail::to($user->email)->send(new AuditCompletedMail($auditRun));
            \Log::info('Audit completion email sent.', [
                'audit_run_id' => $auditRun->id,
                'user_id' => $auditRun->user_id,
                'email' => $user->email,
                'status' => $auditRun->status,
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Failed to send audit completion email.', [
                'audit_run_id' => $auditRun->id,
                'user_id' => $auditRun->user_id,
                'exception' => $e,
            ]);
        }
    }
}
