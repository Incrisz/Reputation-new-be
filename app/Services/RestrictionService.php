<?php

namespace App\Services;

use App\Models\AuditQueueLimit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RestrictionService
{
    public function __construct(
        private SubscriptionService $subscriptionService,
        private UsageService $usageService,
        private PlanService $planService
    ) {
    }

    public function plansAreActive(): bool
    {
        return (bool) config('plans.active', false);
    }

    /**
     * @return array{allowed: bool, code?: string, message?: string, details?: array}
     */
    public function checkCanStartAudit(?User $user): array
    {
        if (!$this->plansAreActive()) {
            return [
                'allowed' => true,
                'details' => [
                    'plans_active' => false,
                ],
            ];
        }

        if (!$user) {
            return [
                'allowed' => false,
                'code' => 'USER_REQUIRED',
                'message' => 'Sign in to start an audit.',
            ];
        }

        $subscription = $this->subscriptionService->getOrCreateActiveSubscription($user);
        if (!$subscription) {
            return [
                'allowed' => false,
                'code' => 'PLAN_CONFIGURATION_ERROR',
                'message' => 'No active plans are configured. Please contact support.',
            ];
        }

        if ($subscription->status !== 'active') {
            return [
                'allowed' => false,
                'code' => 'SUBSCRIPTION_INACTIVE',
                'message' => 'Your subscription is not active. Update your plan to continue.',
            ];
        }

        $plan = $subscription->plan;
        if (!$plan || !$plan->is_active) {
            return [
                'allowed' => false,
                'code' => 'PLAN_INACTIVE',
                'message' => 'Your plan is no longer active. Please choose another plan.',
            ];
        }

        $limits = $this->planService->getPlanFeatureLimits($plan);
        $usage = $this->usageService->getUsageStats($user);

        $maxAudits = $limits[PlanService::FEATURE_MAX_AUDITS_PER_MONTH] ?? null;
        $usedAudits = (int) ($usage['audits_used'] ?? 0);

        if (!is_null($maxAudits) && $usedAudits >= (int) $maxAudits) {
            return [
                'allowed' => false,
                'code' => 'PLAN_AUDIT_LIMIT_REACHED',
                'message' => "You've used {$usedAudits}/{$maxAudits} audits this month. Upgrade to run more.",
                'details' => [
                    'audits_used' => $usedAudits,
                    'audits_limit' => (int) $maxAudits,
                ],
            ];
        }

        $concurrentAllowed = $this->resolveConcurrentLimitForUser($user, $limits);
        $queueLimit = AuditQueueLimit::query()->where('user_id', $user->id)->first();
        $currentRunning = (int) ($queueLimit?->current_running_count ?? 0);

        if ($currentRunning >= $concurrentAllowed) {
            return [
                'allowed' => false,
                'code' => 'PLAN_CONCURRENT_LIMIT_REACHED',
                'message' => "You currently have {$currentRunning}/{$concurrentAllowed} audits running. Wait for one to complete or upgrade your plan.",
                'details' => [
                    'concurrent_running' => $currentRunning,
                    'concurrent_allowed' => $concurrentAllowed,
                ],
            ];
        }

        return [
            'allowed' => true,
            'details' => [
                'audits_used' => $usedAudits,
                'audits_limit' => is_null($maxAudits) ? null : (int) $maxAudits,
                'concurrent_running' => $currentRunning,
                'concurrent_allowed' => $concurrentAllowed,
            ],
        ];
    }

    /**
     * @return array{allowed: bool, code?: string, message?: string, details?: array}
     */
    public function reserveAuditSlot(?User $user): array
    {
        $eligibility = $this->checkCanStartAudit($user);
        if (!($eligibility['allowed'] ?? false)) {
            return $eligibility;
        }

        if (!$this->plansAreActive() || !$user) {
            return $eligibility;
        }

        $subscription = $this->subscriptionService->getOrCreateActiveSubscription($user);
        if (!$subscription || !$subscription->plan) {
            return [
                'allowed' => false,
                'code' => 'PLAN_CONFIGURATION_ERROR',
                'message' => 'Unable to load plan restrictions. Please try again.',
            ];
        }

        $limits = $this->planService->getPlanFeatureLimits($subscription->plan);
        $concurrentAllowed = $this->resolveConcurrentLimitForUser($user, $limits);

        return DB::transaction(function () use ($user, $concurrentAllowed) {
            $queueLimit = AuditQueueLimit::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (!$queueLimit) {
                $queueLimit = AuditQueueLimit::query()->create([
                    'user_id' => $user->id,
                    'concurrent_audits_allowed' => $concurrentAllowed,
                    'current_running_count' => 0,
                ]);
            }

            if ((int) $queueLimit->concurrent_audits_allowed !== $concurrentAllowed) {
                $queueLimit->concurrent_audits_allowed = $concurrentAllowed;
            }

            if ((int) $queueLimit->current_running_count >= $concurrentAllowed) {
                return [
                    'allowed' => false,
                    'code' => 'PLAN_CONCURRENT_LIMIT_REACHED',
                    'message' => "You currently have {$queueLimit->current_running_count}/{$concurrentAllowed} audits running. Wait for one to complete or upgrade your plan.",
                    'details' => [
                        'concurrent_running' => (int) $queueLimit->current_running_count,
                        'concurrent_allowed' => $concurrentAllowed,
                    ],
                ];
            }

            $queueLimit->current_running_count = ((int) $queueLimit->current_running_count) + 1;
            $queueLimit->save();

            return [
                'allowed' => true,
                'details' => [
                    'concurrent_running' => (int) $queueLimit->current_running_count,
                    'concurrent_allowed' => $concurrentAllowed,
                ],
            ];
        });
    }

    public function releaseAuditSlot(?User $user): void
    {
        if (!$this->plansAreActive() || !$user) {
            return;
        }

        DB::transaction(function () use ($user) {
            $queueLimit = AuditQueueLimit::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (!$queueLimit) {
                return;
            }

            $queueLimit->current_running_count = max(0, ((int) $queueLimit->current_running_count) - 1);
            $queueLimit->save();
        });
    }

    private function resolveConcurrentLimitForUser(User $user, array $planLimits): int
    {
        $featureLimit = $planLimits[PlanService::FEATURE_CONCURRENT_AUDITS_ALLOWED] ?? null;
        $featureLimit = is_null($featureLimit) ? null : (int) $featureLimit;

        $storedLimit = AuditQueueLimit::query()
            ->where('user_id', $user->id)
            ->value('concurrent_audits_allowed');
        $storedLimit = is_null($storedLimit) ? null : (int) $storedLimit;

        return max(1, $featureLimit ?? $storedLimit ?? 1);
    }
}
