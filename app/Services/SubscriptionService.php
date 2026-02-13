<?php

namespace App\Services;

use App\Models\Plan;
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
                'billing_interval' => 'monthly',
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
        $billingInterval = (string) ($subscription->billing_interval ?? '');
        $isYearly = $billingInterval === 'annual'
            ? true
            : ($billingInterval === 'monthly' ? false : $cycleDays >= 330);

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

    public function activatePlan(
        User $user,
        Plan $plan,
        string $paymentMethod,
        string $billingInterval = 'monthly',
        array $billingMeta = []
    ): UserSubscription {
        $billingInterval = $billingInterval === 'annual' ? 'annual' : 'monthly';

        $now = now();
        $renewsAt = $billingInterval === 'annual'
            ? $now->copy()->addYear()
            : $now->copy()->addMonth();

        $subscription = UserSubscription::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->orderByDesc('renews_at')
            ->orderByDesc('started_at')
            ->first();

        if (!$subscription) {
            $subscription = $this->getCurrentSubscription($user);
        }

        if (!$subscription) {
            $subscription = new UserSubscription([
                'user_id' => $user->id,
            ]);
        }

        $subscription->forceFill([
            'plan_id' => $plan->id,
            'status' => 'active',
            'started_at' => $now,
            'renews_at' => $renewsAt,
            'payment_method' => $paymentMethod,
            'billing_interval' => $billingInterval,
            'stripe_customer_id' => $billingMeta['stripe_customer_id'] ?? $subscription->stripe_customer_id,
            'stripe_subscription_id' => $billingMeta['stripe_subscription_id'] ?? $subscription->stripe_subscription_id,
            'stripe_checkout_session_id' => $billingMeta['stripe_checkout_session_id'] ?? $subscription->stripe_checkout_session_id,
            'last_payment_at' => $billingMeta['last_payment_at'] ?? (
                $paymentMethod === 'stripe' ? $now : $subscription->last_payment_at
            ),
        ])->save();

        return $subscription->fresh(['plan.features']) ?? $subscription;
    }

    public function syncStripeSubscriptionStatus(array $stripeSubscription): ?UserSubscription
    {
        $stripeSubscriptionId = (string) ($stripeSubscription['id'] ?? '');
        if ($stripeSubscriptionId === '') {
            return null;
        }

        $subscription = UserSubscription::query()
            ->where('stripe_subscription_id', $stripeSubscriptionId)
            ->with('plan.features')
            ->first();

        if (!$subscription) {
            return null;
        }

        $stripeStatus = (string) ($stripeSubscription['status'] ?? '');
        $mappedStatus = match ($stripeStatus) {
            'active', 'trialing' => 'active',
            'canceled', 'unpaid', 'incomplete_expired' => 'cancelled',
            default => 'suspended',
        };

        $interval = data_get($stripeSubscription, 'items.data.0.plan.interval');
        $billingInterval = $interval === 'year' ? 'annual' : 'monthly';
        $periodEnd = data_get($stripeSubscription, 'current_period_end');
        $renewsAt = is_numeric($periodEnd)
            ? Carbon::createFromTimestamp((int) $periodEnd)
            : $subscription->renews_at;

        $subscription->forceFill([
            'status' => $mappedStatus,
            'billing_interval' => $billingInterval,
            'renews_at' => $renewsAt,
            'stripe_customer_id' => (string) ($stripeSubscription['customer'] ?? $subscription->stripe_customer_id),
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
            'billing_interval' => $subscription->billing_interval,
            'plan' => $subscription->plan
                ? $this->planService->serializePlan($subscription->plan)
                : null,
        ];
    }
}
