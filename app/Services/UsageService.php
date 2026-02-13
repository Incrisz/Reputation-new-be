<?php

namespace App\Services;

use App\Models\User;
use App\Models\UsageTracking;
use Carbon\Carbon;

class UsageService
{
    public const FEATURE_AUDITS_COMPLETED = 'audits_completed';

    public function __construct(
        private SubscriptionService $subscriptionService,
        private PlanService $planService
    ) {
    }

    /**
     * @return array{period_start: Carbon, period_end: Carbon, subscription_id: int|null}
     */
    public function resolveCurrentUsagePeriod(User $user): array
    {
        $subscription = $this->subscriptionService->getOrCreateActiveSubscription($user);

        if (!$subscription) {
            $start = now()->startOfMonth();
            $end = now()->endOfMonth();

            return [
                'period_start' => $start,
                'period_end' => $end,
                'subscription_id' => null,
            ];
        }

        $subscription = $this->subscriptionService->ensureSubscriptionPeriodCurrent($subscription);
        $periodStart = $subscription->started_at
            ? Carbon::parse($subscription->started_at)->startOfDay()
            : now()->startOfMonth();
        $periodEnd = $subscription->renews_at
            ? Carbon::parse($subscription->renews_at)->subDay()->endOfDay()
            : now()->endOfMonth();

        return [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'subscription_id' => $subscription->id,
        ];
    }

    public function getUsageCount(
        User $user,
        string $featureName,
        Carbon $periodStart,
        Carbon $periodEnd
    ): int {
        $record = UsageTracking::query()
            ->where('user_id', $user->id)
            ->where('feature_name', $featureName)
            ->whereDate('period_start', $periodStart->toDateString())
            ->whereDate('period_end', $periodEnd->toDateString())
            ->first();

        return (int) ($record?->usage_count ?? 0);
    }

    public function incrementUsage(User $user, string $featureName, int $amount = 1): UsageTracking
    {
        $period = $this->resolveCurrentUsagePeriod($user);

        $record = UsageTracking::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'feature_name' => $featureName,
                'period_start' => $period['period_start']->toDateString(),
                'period_end' => $period['period_end']->toDateString(),
            ],
            [
                'usage_count' => 0,
            ]
        );

        if ($amount > 0) {
            $record->increment('usage_count', $amount);
            $record->refresh();
        }

        return $record;
    }

    public function getUsageStats(User $user): array
    {
        $subscription = $this->subscriptionService->getOrCreateActiveSubscription($user);
        $limits = $subscription && $subscription->plan
            ? $this->planService->getPlanFeatureLimits($subscription->plan)
            : [];

        $period = $this->resolveCurrentUsagePeriod($user);
        $auditsUsed = $this->getUsageCount(
            $user,
            self::FEATURE_AUDITS_COMPLETED,
            $period['period_start'],
            $period['period_end']
        );

        $maxAudits = $limits[PlanService::FEATURE_MAX_AUDITS_PER_MONTH] ?? null;
        $auditsRemaining = is_null($maxAudits)
            ? null
            : max(0, ((int) $maxAudits) - $auditsUsed);

        return [
            'period_start' => $period['period_start']->toDateString(),
            'period_end' => $period['period_end']->toDateString(),
            'audits_used' => $auditsUsed,
            'audits_limit' => $maxAudits,
            'audits_remaining' => $auditsRemaining,
            'storage_used_mb' => null,
            'storage_limit_mb' => null,
        ];
    }
}
