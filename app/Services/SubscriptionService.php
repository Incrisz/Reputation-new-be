<?php

namespace App\Services;

use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserPlanEntitlement;
use App\Models\UserSubscription;
use Carbon\Carbon;
use Illuminate\Support\Collection;

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
        $startsAt = isset($billingMeta['started_at'])
            ? Carbon::parse($billingMeta['started_at'])
            : $now->copy();
        $renewsAt = isset($billingMeta['renews_at'])
            ? Carbon::parse($billingMeta['renews_at'])
            : ($billingInterval === 'annual'
                ? $startsAt->copy()->addYear()
                : $startsAt->copy()->addMonth());

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
            'started_at' => $startsAt,
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

    public function getReusableEntitlement(User $user, Plan $plan): ?UserPlanEntitlement
    {
        $this->markExpiredEntitlements($user);

        return UserPlanEntitlement::query()
            ->where('user_id', $user->id)
            ->where('plan_id', $plan->id)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->orderByDesc('expires_at')
            ->first();
    }

    public function markExpiredEntitlements(User $user): void
    {
        UserPlanEntitlement::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);
    }

    public function createOrRefreshEntitlement(
        User $user,
        Plan $plan,
        string $billingInterval,
        array $meta = []
    ): UserPlanEntitlement {
        $billingInterval = $billingInterval === 'annual' ? 'annual' : 'monthly';
        $startsAt = isset($meta['starts_at'])
            ? Carbon::parse($meta['starts_at'])
            : now();
        $expiresAt = isset($meta['expires_at'])
            ? Carbon::parse($meta['expires_at'])
            : ($billingInterval === 'annual'
                ? $startsAt->copy()->addYear()
                : $startsAt->copy()->addMonth());

        $stripeSubscriptionId = isset($meta['stripe_subscription_id']) && is_string($meta['stripe_subscription_id'])
            ? $meta['stripe_subscription_id']
            : null;
        $stripeCheckoutSessionId = isset($meta['stripe_checkout_session_id']) && is_string($meta['stripe_checkout_session_id'])
            ? $meta['stripe_checkout_session_id']
            : null;

        $existing = null;
        if ($stripeSubscriptionId) {
            $existing = UserPlanEntitlement::query()
                ->where('user_id', $user->id)
                ->where('plan_id', $plan->id)
                ->where('stripe_subscription_id', $stripeSubscriptionId)
                ->first();
        }

        if (!$existing && $stripeCheckoutSessionId) {
            $existing = UserPlanEntitlement::query()
                ->where('user_id', $user->id)
                ->where('plan_id', $plan->id)
                ->where('stripe_checkout_session_id', $stripeCheckoutSessionId)
                ->first();
        }

        $payload = [
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => $expiresAt->greaterThan(now()) ? 'active' : 'expired',
            'billing_interval' => $billingInterval,
            'source' => (string) ($meta['source'] ?? 'stripe'),
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'stripe_customer_id' => $meta['stripe_customer_id'] ?? null,
            'stripe_subscription_id' => $stripeSubscriptionId,
            'stripe_checkout_session_id' => $stripeCheckoutSessionId,
            'metadata' => is_array($meta['metadata'] ?? null) ? $meta['metadata'] : null,
        ];

        if ($existing) {
            $existing->forceFill($payload)->save();
            return $existing->fresh() ?? $existing;
        }

        return UserPlanEntitlement::query()->create($payload);
    }

    public function recordPaymentTransaction(
        User $user,
        Plan $plan,
        ?UserSubscription $subscription,
        array $payload = []
    ): PaymentTransaction {
        $provider = (string) ($payload['provider'] ?? 'stripe');
        $providerSessionId = isset($payload['provider_session_id']) && is_string($payload['provider_session_id'])
            ? $payload['provider_session_id']
            : null;

        if ($providerSessionId) {
            $existing = PaymentTransaction::query()
                ->where('provider', $provider)
                ->where('provider_session_id', $providerSessionId)
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        return PaymentTransaction::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'user_subscription_id' => $subscription?->id,
            'provider' => $provider,
            'transaction_type' => (string) ($payload['transaction_type'] ?? 'charge'),
            'provider_transaction_id' => isset($payload['provider_transaction_id']) && is_string($payload['provider_transaction_id'])
                ? $payload['provider_transaction_id']
                : null,
            'provider_session_id' => $providerSessionId,
            'provider_subscription_id' => isset($payload['provider_subscription_id']) && is_string($payload['provider_subscription_id'])
                ? $payload['provider_subscription_id']
                : null,
            'provider_customer_id' => isset($payload['provider_customer_id']) && is_string($payload['provider_customer_id'])
                ? $payload['provider_customer_id']
                : null,
            'billing_interval' => ($payload['billing_interval'] ?? null) === 'annual' ? 'annual' : 'monthly',
            'amount' => (float) ($payload['amount'] ?? 0),
            'currency' => strtoupper((string) ($payload['currency'] ?? 'USD')),
            'status' => (string) ($payload['status'] ?? 'paid'),
            'paid_at' => isset($payload['paid_at']) ? Carbon::parse($payload['paid_at']) : now(),
            'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : null,
        ]);
    }

    public function getPaymentHistory(User $user, int $limit = 20): Collection
    {
        $limit = max(1, min($limit, 200));

        return PaymentTransaction::query()
            ->where('user_id', $user->id)
            ->with('plan')
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function serializePaymentTransaction(PaymentTransaction $transaction): array
    {
        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
        $entitlementStartsAt = isset($metadata['entitlement_starts_at']) && is_string($metadata['entitlement_starts_at'])
            ? $metadata['entitlement_starts_at']
            : null;
        $entitlementExpiresAt = isset($metadata['entitlement_expires_at']) && is_string($metadata['entitlement_expires_at'])
            ? $metadata['entitlement_expires_at']
            : null;

        return [
            'id' => $transaction->id,
            'provider' => $transaction->provider,
            'transaction_type' => $transaction->transaction_type,
            'status' => $transaction->status,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
            'billing_interval' => $transaction->billing_interval,
            'paid_at' => $transaction->paid_at?->toISOString(),
            'created_at' => $transaction->created_at?->toISOString(),
            'provider_transaction_id' => $transaction->provider_transaction_id,
            'provider_session_id' => $transaction->provider_session_id,
            'entitlement_starts_at' => $entitlementStartsAt,
            'entitlement_expires_at' => $entitlementExpiresAt,
            'plan' => $transaction->plan
                ? $this->planService->serializePlan($transaction->plan)
                : null,
        ];
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
