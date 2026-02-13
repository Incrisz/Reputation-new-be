<?php

namespace Tests\Feature;

use App\Jobs\RunAuditScanJob;
use App\Models\AuditRun;
use App\Models\User;
use App\Services\GooglePlacesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class ReputationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_queues_audit_run_for_user(): void
    {
        Queue::fake();

        $user = User::factory()->create([
            'email' => 'audit-user@example.com',
        ]);

        $this->mock(GooglePlacesService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('searchCandidates')->never();
        });

        $response = $this->postJson('/api/reputation/scan', [
            'user_id' => $user->id,
            'business_name' => 'Acme Corp',
            'website' => 'https://acme.test',
            'location' => 'Austin, TX',
            'skip_places' => true,
        ]);

        $response
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('audit.status', 'pending')
            ->assertJsonPath('audit.business_name', 'Acme Corp')
            ->assertJsonPath('audit_id', fn ($value) => is_int($value) && $value > 0);

        $auditId = (int) $response->json('audit_id');

        $this->assertDatabaseHas('audit_runs', [
            'id' => $auditId,
            'user_id' => $user->id,
            'status' => 'pending',
            'business_name' => 'Acme Corp',
            'website' => 'https://acme.test',
        ]);

        Queue::assertPushed(RunAuditScanJob::class, function (RunAuditScanJob $job) use ($auditId): bool {
            return $job->auditRunId === $auditId;
        });
    }

    public function test_scan_selection_required_persists_audit_run(): void
    {
        Queue::fake();

        $user = User::factory()->create([
            'email' => 'selection-user@example.com',
        ]);

        $this->mock(GooglePlacesService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('searchCandidates')
                ->once()
                ->andReturn([
                    'success' => true,
                    'candidates' => [
                        [
                            'place_id' => 'place-123',
                            'name' => 'Acme Corp HQ',
                            'address' => 'Austin, TX',
                            'rating' => 4.4,
                            'review_count' => 120,
                        ],
                    ],
                ]);
        });

        $response = $this->postJson('/api/reputation/scan', [
            'user_id' => $user->id,
            'business_name' => 'Acme Corp',
            'location' => 'Austin, TX',
            'skip_places' => false,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'selection_required')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('audit_id', fn ($value) => is_int($value) && $value > 0);

        $auditId = (int) $response->json('audit_id');

        $this->assertDatabaseHas('audit_runs', [
            'id' => $auditId,
            'user_id' => $user->id,
            'status' => 'selection_required',
            'business_name' => 'Acme Corp',
        ]);

        Queue::assertNothingPushed();
    }

    public function test_scan_reuses_existing_selection_audit_instead_of_creating_new_record(): void
    {
        Queue::fake();

        $user = User::factory()->create([
            'email' => 'selection-reuse@example.com',
        ]);

        $existingAudit = AuditRun::query()->create([
            'user_id' => $user->id,
            'status' => 'selection_required',
            'business_name' => 'Acme Corp',
            'request_payload' => [
                'user_id' => $user->id,
                'business_name' => 'Acme Corp',
                'location' => 'Austin, TX',
                'skip_places' => false,
            ],
            'response_payload' => [
                'status' => 'selection_required',
                'message' => 'Select your business.',
                'candidates' => [
                    [
                        'place_id' => 'place-123',
                        'name' => 'Acme Corp HQ',
                        'address' => 'Austin, TX',
                    ],
                ],
                'total' => 1,
            ],
        ]);

        $this->mock(GooglePlacesService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('searchCandidates')->never();
        });

        $response = $this->postJson('/api/reputation/scan', [
            'user_id' => $user->id,
            'audit_id' => $existingAudit->id,
            'business_name' => 'Acme Corp',
            'location' => 'Austin, TX',
            'place_id' => 'place-123',
            'skip_places' => false,
        ]);

        $response
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('audit.id', $existingAudit->id)
            ->assertJsonPath('audit_id', $existingAudit->id);

        $this->assertDatabaseCount('audit_runs', 1);
        $this->assertDatabaseHas('audit_runs', [
            'id' => $existingAudit->id,
            'user_id' => $user->id,
            'status' => 'pending',
            'place_id' => 'place-123',
        ]);

        Queue::assertPushed(RunAuditScanJob::class, function (RunAuditScanJob $job) use ($existingAudit): bool {
            return $job->auditRunId === $existingAudit->id;
        });
    }

    public function test_history_returns_only_requested_users_audits(): void
    {
        $userOne = User::factory()->create([
            'email' => 'history-one@example.com',
        ]);
        $userTwo = User::factory()->create([
            'email' => 'history-two@example.com',
        ]);

        $runOne = AuditRun::query()->create([
            'user_id' => $userOne->id,
            'status' => 'success',
            'business_name' => 'Biz One',
            'website' => 'https://one.test',
            'reputation_score' => 77,
            'scan_date' => now()->subDay(),
        ]);

        $runTwo = AuditRun::query()->create([
            'user_id' => $userOne->id,
            'status' => 'pending',
            'business_name' => 'Biz Two',
            'website' => 'https://two.test',
        ]);

        AuditRun::query()->create([
            'user_id' => $userTwo->id,
            'status' => 'success',
            'business_name' => 'Other User Biz',
            'website' => 'https://other.test',
            'reputation_score' => 88,
        ]);

        $response = $this->getJson('/api/reputation/history?user_id='.$userOne->id);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('total', 2)
            ->assertJsonCount(2, 'audits');

        $response->assertJsonFragment(['id' => $runOne->id, 'business_name' => 'Biz One']);
        $response->assertJsonFragment(['id' => $runTwo->id, 'business_name' => 'Biz Two']);
        $response->assertJsonMissing(['business_name' => 'Other User Biz']);
    }

    public function test_history_item_is_scoped_to_requesting_user(): void
    {
        $owner = User::factory()->create([
            'email' => 'audit-owner@example.com',
        ]);
        $otherUser = User::factory()->create([
            'email' => 'other-user@example.com',
        ]);

        $audit = AuditRun::query()->create([
            'user_id' => $owner->id,
            'status' => 'success',
            'business_name' => 'Scoped Biz',
            'website' => 'https://scoped.test',
            'reputation_score' => 74,
            'response_payload' => [
                'status' => 'success',
                'business_name' => 'Scoped Biz',
                'results' => [
                    'reputation_score' => 74,
                ],
            ],
        ]);

        $this->getJson('/api/reputation/history/'.$audit->id.'?user_id='.$owner->id)
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('audit.id', $audit->id)
            ->assertJsonPath('audit.scan_response.status', 'success');

        $this->getJson('/api/reputation/history/'.$audit->id.'?user_id='.$otherUser->id)
            ->assertStatus(404)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('code', 'AUDIT_NOT_FOUND');
    }
}
