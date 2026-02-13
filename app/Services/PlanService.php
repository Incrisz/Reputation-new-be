<?php

namespace App\Services;

use App\Models\Plan;
use Illuminate\Support\Collection;

class PlanService
{
    public const FEATURE_MAX_AUDITS_PER_MONTH = 'max_audits_per_month';
    public const FEATURE_CONCURRENT_AUDITS_ALLOWED = 'concurrent_audits_allowed';

    public function getActivePlans(): Collection
    {
        return Plan::query()
            ->where('is_active', true)
            ->with('features')
            ->orderBy('price_monthly')
            ->get();
    }

    public function getDefaultPlan(): ?Plan
    {
        return Plan::query()
            ->where('is_active', true)
            ->whereRaw('LOWER(name) = ?', ['free'])
            ->with('features')
            ->first()
            ?? Plan::query()
                ->where('is_active', true)
                ->with('features')
                ->orderBy('price_monthly')
                ->first();
    }

    /**
     * @return array<string, int|null>
     */
    public function getPlanFeatureLimits(Plan $plan): array
    {
        $limits = [];
        foreach ($plan->features as $feature) {
            $limits[$feature->feature_name] = $feature->limit_value;
        }

        return $limits;
    }

    public function serializePlan(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'description' => $plan->description,
            'price_monthly' => (float) $plan->price_monthly,
            'price_yearly' => (float) $plan->price_yearly,
            'is_active' => (bool) $plan->is_active,
            'is_custom' => (bool) $plan->is_custom,
            'contact_sales' => (bool) $plan->contact_sales,
            'pricing_label' => $plan->contact_sales ? 'Contact Sales' : null,
            'features' => $plan->features
                ->mapWithKeys(function ($feature) {
                    return [$feature->feature_name => $feature->limit_value];
                })
                ->toArray(),
        ];
    }
}
