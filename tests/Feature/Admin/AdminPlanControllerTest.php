<?php

namespace Tests\Feature\Admin;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPlanControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_plans(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        Plan::query()->create([
            'name' => 'Starter',
            'description' => 'Starter plan',
            'price_monthly' => 10,
            'price_yearly' => 100,
            'is_active' => true,
            'is_custom' => false,
            'contact_sales' => false,
        ]);

        $this->getJson('/api/admin/plans?admin_user_id='.$admin->id)
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('plans.0.name', 'Starter');
    }

    public function test_admin_can_create_and_update_plan(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $createResponse = $this->postJson('/api/admin/plans', [
            'admin_user_id' => $admin->id,
            'name' => 'Growth',
            'description' => 'Growth tier',
            'price_monthly' => 29,
            'price_yearly' => 290,
            'is_active' => true,
            'is_custom' => false,
            'contact_sales' => false,
            'features' => [
                'max_audits_per_month' => 120,
                'concurrent_audits_allowed' => 3,
            ],
        ]);

        $createResponse
            ->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('plan.name', 'Growth')
            ->assertJsonPath('plan.features.max_audits_per_month', 120);

        $planId = (int) $createResponse->json('plan.id');

        $this->putJson('/api/admin/plans/'.$planId, [
            'admin_user_id' => $admin->id,
            'name' => 'Growth Plus',
            'description' => 'Updated growth tier',
            'price_monthly' => 39,
            'price_yearly' => 390,
            'is_active' => true,
            'is_custom' => true,
            'contact_sales' => true,
            'features' => [
                'max_audits_per_month' => 240,
                'concurrent_audits_allowed' => 5,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('plan.name', 'Growth Plus')
            ->assertJsonPath('plan.features.max_audits_per_month', 240)
            ->assertJsonPath('plan.features.concurrent_audits_allowed', 5);
    }

    public function test_admin_can_delete_unused_plan(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $plan = Plan::query()->create([
            'name' => 'Delete Me',
            'description' => 'Temporary',
            'price_monthly' => 12,
            'price_yearly' => 120,
            'is_active' => true,
            'is_custom' => false,
            'contact_sales' => false,
        ]);

        $this->deleteJson('/api/admin/plans/'.$plan->id.'?admin_user_id='.$admin->id)
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseMissing('plans', [
            'id' => $plan->id,
        ]);
    }
}
