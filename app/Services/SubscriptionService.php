<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSubscription;
use Carbon\Carbon;

class SubscriptionService
{
    public function __construct(
        private PlanService $planService,
        private CompanyPlanService $companyPlanService
    ) {
    }

    public function getCurrentSubscription(User $user): ?UserSubscription
    {
        return UserSubscription::query()
            ->where('user_id', $user->id)
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('renews_at')
            ->orderByDesc('started_at')
            ->with('plan.features')
            ->first();
    }

    public function getOrCreateActiveSubscription(User $user): ?UserSubscription
    {
        $subscription = UserSubscription::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->orderByDesc('renews_at')
            ->orderByDesc('started_at')
            ->with('plan.features')
            ->first();

        if (!$subscription) {
            $selectedPlan = $this->companyPlanService->getAllocatedPlanForCompany($user->company)
                ?? $this->planService->getDefaultPlan();
            if (!$selectedPlan) {
                return null;
            }

            $now = now();
            $subscription = UserSubscription::query()->create([
                'user_id' => $user->id,
                'plan_id' => $selectedPlan->id,
                'status' => 'active',
                'started_at' => $now,
                'renews_at' => $now->copy()->addMonth(),
                'payment_method' => 'system_default',
            ]);

            $subscription->load('plan.features');
        }

        $allocatedPlan = $this->companyPlanService->getAllocatedPlanForCompany($user->company);
        if ($allocatedPlan && (int) $subscription->plan_id !== (int) $allocatedPlan->id) {
            $subscription->forceFill([
                'plan_id' => $allocatedPlan->id,
                'status' => 'active',
            ])->save();
            $subscription->load('plan.features');
        }

        return $this->ensureSubscriptionPeriodCurrent($subscription);
    }

    public function ensureSubscriptionPeriodCurrent(UserSubscription $subscription): UserSubscription
    {
        if ($subscription->status !== 'active') {
            return $subscription;
        }

        $now = now();
        $startedAt = $subscription->started_at ? Carbon::parse($subscription->started_at) : $now->copy();
        $renewsAt = $subscription->renews_at ? Carbon::parse($subscription->renews_at) : $startedAt->copy()->addMonth();

        if ($renewsAt->greaterThan($now)) {
            if (!$subscription->renews_at) {
                $subscription->renews_at = $renewsAt;
                $subscription->save();
            }
            return $subscription;
        }

        $cycleDays = max(1, $startedAt->diffInDays($renewsAt));
        $isYearly = $cycleDays >= 330;

        while ($renewsAt->lessThanOrEqualTo($now)) {
            $startedAt = $renewsAt->copy();
            $renewsAt = $isYearly
                ? $renewsAt->copy()->addYear()
                : $renewsAt->copy()->addMonth();
        }

        $subscription->forceFill([
            'started_at' => $startedAt,
            'renews_at' => $renewsAt,
        ])->save();

        return $subscription->fresh(['plan.features']) ?? $subscription;
    }

    public function serializeSubscription(UserSubscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'status' => $subscription->status,
            'started_at' => $subscription->started_at?->toISOString(),
            'renews_at' => $subscription->renews_at?->toISOString(),
            'payment_method' => $subscription->payment_method,
            'plan' => $subscription->plan
                ? $this->planService->serializePlan($subscription->plan)
                : null,
        ];
    }
}
