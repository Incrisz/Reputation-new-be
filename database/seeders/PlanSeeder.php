<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Services\PlanService;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Seed the application's plans and plan features.
     */
    public function run(): void
    {
        $definitions = [
            [
                'name' => 'Free',
                'description' => 'Starter plan for trying AI reputation audits.',
                'price_monthly' => 0,
                'price_yearly' => 0,
                'is_active' => true,
                'is_custom' => false,
                'contact_sales' => false,
                'features' => [
                    PlanService::FEATURE_MAX_AUDITS_PER_MONTH => 5,
                    PlanService::FEATURE_CONCURRENT_AUDITS_ALLOWED => 1,
                ],
            ],
            [
                'name' => 'Professional',
                'description' => 'For growing businesses with higher monthly audit volume.',
                'price_monthly' => 29,
                'price_yearly' => 290,
                'is_active' => true,
                'is_custom' => false,
                'contact_sales' => false,
                'features' => [
                    PlanService::FEATURE_MAX_AUDITS_PER_MONTH => 100,
                    PlanService::FEATURE_CONCURRENT_AUDITS_ALLOWED => 3,
                ],
            ],
            [
                'name' => 'Enterprise',
                'description' => 'For teams that need high-volume AI audits and concurrency.',
                'price_monthly' => 99,
                'price_yearly' => 990,
                'is_active' => true,
                'is_custom' => false,
                'contact_sales' => false,
                'features' => [
                    PlanService::FEATURE_MAX_AUDITS_PER_MONTH => 1000,
                    PlanService::FEATURE_CONCURRENT_AUDITS_ALLOWED => 10,
                ],
            ],
            [
                'name' => 'Enterprise Custom',
                'description' => 'Custom enterprise plan. Contact sales for tailored limits, support, and pricing.',
                'price_monthly' => 0,
                'price_yearly' => 0,
                'is_active' => true,
                'is_custom' => true,
                'contact_sales' => true,
                'features' => [
                    PlanService::FEATURE_MAX_AUDITS_PER_MONTH => 10000,
                    PlanService::FEATURE_CONCURRENT_AUDITS_ALLOWED => 25,
                ],
            ],
        ];

        foreach ($definitions as $definition) {
            $plan = Plan::query()->updateOrCreate(
                ['name' => $definition['name']],
                [
                    'description' => $definition['description'],
                    'price_monthly' => $definition['price_monthly'],
                    'price_yearly' => $definition['price_yearly'],
                    'is_active' => $definition['is_active'],
                    'is_custom' => $definition['is_custom'],
                    'contact_sales' => $definition['contact_sales'],
                ]
            );

            foreach ($definition['features'] as $featureName => $limitValue) {
                $plan->features()->updateOrCreate(
                    ['feature_name' => $featureName],
                    ['limit_value' => $limitValue]
                );
            }
        }
    }
}
