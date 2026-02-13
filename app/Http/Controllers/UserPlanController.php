<?php

namespace App\Http\Controllers;

use App\Models\AuditQueueLimit;
use App\Models\User;
use App\Services\PlanService;
use App\Services\RestrictionService;
use App\Services\SubscriptionService;
use App\Services\UsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserPlanController extends Controller
{
    public function __construct(
        private PlanService $planService,
        private SubscriptionService $subscriptionService,
        private UsageService $usageService,
        private RestrictionService $restrictionService
    ) {
    }

    public function plans(): JsonResponse
    {
        $plans = $this->planService->getActivePlans()
            ->map(fn ($plan) => $this->planService->serializePlan($plan))
            ->values();

        return response()->json([
            'status' => 'success',
            'plans_active' => $this->restrictionService->plansAreActive(),
            'total' => $plans->count(),
            'plans' => $plans,
        ]);
    }

    public function currentPlan(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'user_id' => 'nullable|integer|exists:users,id',
                'lookup_email' => 'nullable|string|email',
            ]);

            $user = $this->resolveUserFromRequest($validated);
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found.',
                ], 404);
            }

            if (!$this->restrictionService->plansAreActive()) {
                return response()->json([
                    'status' => 'success',
                    'plans_active' => false,
                    'message' => 'Plan restrictions are currently disabled.',
                    'plan' => null,
                    'subscription' => null,
                    'usage' => null,
                ]);
            }

            $subscription = $this->subscriptionService->getOrCreateActiveSubscription($user);
            if (!$subscription || !$subscription->plan) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No subscription plan is configured for this user.',
                ], 422);
            }

            $usage = $this->usageService->getUsageStats($user);
            $queueLimit = AuditQueueLimit::query()->where('user_id', $user->id)->first();

            return response()->json([
                'status' => 'success',
                'plans_active' => true,
                'plan' => $this->planService->serializePlan($subscription->plan),
                'subscription' => $this->subscriptionService->serializeSubscription($subscription),
                'usage' => [
                    ...$usage,
                    'concurrent_running' => (int) ($queueLimit?->current_running_count ?? 0),
                    'concurrent_allowed' => (int) ($queueLimit?->concurrent_audits_allowed ?? 1),
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid current-plan request parameters.',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function usageStats(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'user_id' => 'nullable|integer|exists:users,id',
                'lookup_email' => 'nullable|string|email',
            ]);

            $user = $this->resolveUserFromRequest($validated);
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found.',
                ], 404);
            }

            if (!$this->restrictionService->plansAreActive()) {
                return response()->json([
                    'status' => 'success',
                    'plans_active' => false,
                    'usage' => null,
                    'restrictions' => [
                        'allowed' => true,
                    ],
                ]);
            }

            $usage = $this->usageService->getUsageStats($user);
            $queueLimit = AuditQueueLimit::query()->where('user_id', $user->id)->first();
            $restriction = $this->restrictionService->checkCanStartAudit($user);

            return response()->json([
                'status' => 'success',
                'plans_active' => true,
                'usage' => [
                    ...$usage,
                    'concurrent_running' => (int) ($queueLimit?->current_running_count ?? 0),
                    'concurrent_allowed' => (int) ($queueLimit?->concurrent_audits_allowed ?? 1),
                ],
                'restrictions' => $restriction,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid usage-stats request parameters.',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function subscription(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'user_id' => 'nullable|integer|exists:users,id',
                'lookup_email' => 'nullable|string|email',
            ]);

            $user = $this->resolveUserFromRequest($validated);
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found.',
                ], 404);
            }

            if (!$this->restrictionService->plansAreActive()) {
                return response()->json([
                    'status' => 'success',
                    'plans_active' => false,
                    'subscription' => null,
                ]);
            }

            $subscription = $this->subscriptionService->getOrCreateActiveSubscription($user);
            if (!$subscription) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No subscription configured for this user.',
                ], 422);
            }

            return response()->json([
                'status' => 'success',
                'plans_active' => true,
                'subscription' => $this->subscriptionService->serializeSubscription($subscription),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid subscription request parameters.',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    private function resolveUserFromRequest(array $validated): ?User
    {
        $userId = $validated['user_id'] ?? null;
        if ($userId) {
            return User::query()->find($userId);
        }

        $lookupEmail = $validated['lookup_email'] ?? null;
        if ($lookupEmail) {
            return User::query()->where('email', $lookupEmail)->first();
        }

        return null;
    }
}
