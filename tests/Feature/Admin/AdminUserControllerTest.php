<?php

namespace Tests\Feature\Admin;

use App\Models\AuditRun;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_user_cannot_access_admin_users_endpoint(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
        ]);

        $this->getJson('/api/admin/users?admin_user_id='.$user->id)
            ->assertStatus(403)
            ->assertJsonPath('status', 'error');
    }

    public function test_admin_can_list_users_with_current_plan(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin@example.com',
        ]);

        $customer = User::factory()->create([
            'role' => 'user',
            'email' => 'customer@example.com',
        ]);

        $plan = Plan::query()->create([
            'name' => 'Growth',
            'description' => 'Growth plan',
            'price_monthly' => 49,
            'price_yearly' => 490,
            'is_active' => true,
            'is_custom' => false,
            'contact_sales' => false,
        ]);

        UserSubscription::query()->create([
            'user_id' => $customer->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'started_at' => now()->subDays(5),
            'renews_at' => now()->addDays(25),
            'payment_method' => 'card',
            'billing_interval' => 'monthly',
        ]);

        $response = $this->getJson('/api/admin/users?admin_user_id='.$admin->id);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success');
        $response->assertJsonPath('total', 1);

        $users = collect($response->json('users'));
        $listedCustomer = $users->firstWhere('id', $customer->id);
        $listedAdmin = $users->firstWhere('id', $admin->id);

        $this->assertNotNull($listedCustomer);
        $this->assertNull($listedAdmin);
        $this->assertSame('customer@example.com', $listedCustomer['email']);
        $this->assertSame('Growth', $listedCustomer['current_subscription']['plan']['name']);
    }

    public function test_admin_can_view_user_detail_with_audit_history_and_auth_events(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $customer = User::factory()->create([
            'role' => 'user',
            'email' => 'details@example.com',
        ]);

        AuditRun::query()->create([
            'user_id' => $customer->id,
            'status' => 'success',
            'business_name' => 'Detail Biz',
            'website' => 'https://detail.test',
            'reputation_score' => 82,
            'scan_date' => now(),
            'response_payload' => [
                'status' => 'success',
                'results' => [
                    'reputation_score' => 82,
                ],
            ],
        ]);

        $customer->authEvents()->create([
            'event_type' => 'login',
            'provider' => 'email',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'created_at' => now(),
        ]);

        $response = $this->getJson('/api/admin/users/'.$customer->id.'?admin_user_id='.$admin->id);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('user.id', $customer->id)
            ->assertJsonPath('user.email', 'details@example.com')
            ->assertJsonPath('user.audit_history.0.business_name', 'Detail Biz')
            ->assertJsonPath('user.auth_events.0.event_type', 'login');
    }

    public function test_admin_can_view_single_audit_result_for_user(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $customer = User::factory()->create([
            'role' => 'user',
        ]);

        $audit = AuditRun::query()->create([
            'user_id' => $customer->id,
            'status' => 'success',
            'business_name' => 'Audit Result Biz',
            'response_payload' => [
                'status' => 'success',
                'results' => [
                    'reputation_score' => 90,
                ],
            ],
        ]);

        $this->getJson('/api/admin/users/'.$customer->id.'/audits/'.$audit->id.'?admin_user_id='.$admin->id)
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('audit.id', $audit->id)
            ->assertJsonPath('audit.scan_response.results.reputation_score', 90);
    }
}
