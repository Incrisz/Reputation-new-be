<?php

namespace Tests\Feature;

use App\Jobs\RunAuditScanJob;
use App\Mail\AuditCompletedMail;
use App\Models\AuditRun;
use App\Models\User;
use App\Services\BusinessVerificationService;
use App\Services\ReputationScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;
use Tests\TestCase;

class RunAuditScanJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_marks_audit_success_when_scan_completes(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'audit-success@example.com',
        ]);

        $auditRun = AuditRun::query()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'business_name' => 'Acme Corp',
            'request_payload' => [
                'business_name' => 'Acme Corp',
                'website' => 'https://acme.test',
                'skip_places' => true,
            ],
        ]);

        $verificationService = $this->mock(BusinessVerificationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verify')
                ->once()
                ->andReturn([
                    'success' => true,
                    'business_data' => [
                        'business_name' => 'Acme Corp',
                        'verified_website' => 'https://acme.test',
                        'verified_location' => 'Austin, TX',
                        'verified_phone' => '+15550001111',
                    ],
                ]);
        });

        $scanService = $this->mock(ReputationScanService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scan')
                ->once()
                ->andReturn([
                    'success' => true,
                    'business_name' => 'Acme Corp',
                    'verified_website' => 'https://acme.test',
                    'verified_location' => 'Austin, TX',
                    'verified_phone' => '+15550001111',
                    'scan_date' => now()->toIso8601String(),
                    'results' => [
                        'reputation_score' => 83,
                        'sentiment_breakdown' => ['positive' => 61, 'negative' => 19, 'neutral' => 20],
                        'top_themes' => [],
                        'top_mentions' => [],
                        'recommendations' => [],
                        'audit' => null,
                    ],
                ]);
        });

        (new RunAuditScanJob($auditRun->id))->handle($verificationService, $scanService);

        $auditRun->refresh();

        $this->assertSame('success', $auditRun->status);
        $this->assertSame(83, $auditRun->reputation_score);
        $this->assertSame('Acme Corp', $auditRun->business_name);
        $this->assertNotNull($auditRun->response_payload);
        Mail::assertSent(AuditCompletedMail::class, function (AuditCompletedMail $mail) use ($user): bool {
            return $mail->hasTo($user->email);
        });
    }

    public function test_job_marks_audit_error_when_verification_fails(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'audit-error@example.com',
        ]);

        $auditRun = AuditRun::query()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'business_name' => 'Broken Biz',
            'request_payload' => [
                'business_name' => 'Broken Biz',
                'website' => 'https://broken.test',
                'skip_places' => true,
            ],
        ]);

        $verificationService = $this->mock(BusinessVerificationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verify')
                ->once()
                ->andReturn([
                    'success' => false,
                    'error_code' => 'INVALID_DOMAIN',
                    'message' => 'Domain is not accessible',
                ]);
        });

        $scanService = $this->mock(ReputationScanService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scan')->never();
        });

        (new RunAuditScanJob($auditRun->id))->handle($verificationService, $scanService);

        $auditRun->refresh();

        $this->assertSame('error', $auditRun->status);
        $this->assertSame('INVALID_DOMAIN', $auditRun->error_code);
        $this->assertSame('Domain is not accessible', $auditRun->error_message);
        Mail::assertSent(AuditCompletedMail::class, function (AuditCompletedMail $mail) use ($user): bool {
            return $mail->hasTo($user->email);
        });
    }
}
