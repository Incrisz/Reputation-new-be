<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\StripeBillingService;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BillingController extends Controller
{
    public function __construct(
        private StripeBillingService $stripeBillingService,
        private SubscriptionService $subscriptionService
    ) {
    }

    public function createCheckoutSession(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|integer|exists:users,id',
                'plan_id' => 'required|integer|exists:plans,id',
                'billing_period' => 'nullable|in:monthly,annual',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid billing request payload.',
                'errors' => $e->errors(),
            ], 422);
        }

        $user = User::query()->find((int) $validated['user_id']);
        $plan = Plan::query()->where('is_active', true)->find((int) $validated['plan_id']);
        $billingPeriod = (string) ($validated['billing_period'] ?? 'monthly');

        if (!$user || !$plan) {
            return response()->json([
                'status' => 'error',
                'message' => 'User or plan not found.',
            ], 404);
        }

        if ((bool) $plan->contact_sales) {
            return response()->json([
                'status' => 'error',
                'message' => 'This plan is managed through sales. Contact support to activate it.',
            ], 422);
        }

        $planPrice = $billingPeriod === 'annual'
            ? (float) $plan->price_yearly
            : (float) $plan->price_monthly;

        if ($planPrice > 0) {
            $existingEntitlement = $this->subscriptionService->getReusableEntitlement($user, $plan);
            if ($existingEntitlement) {
                $subscription = $this->subscriptionService->activatePlan(
                    $user,
                    $plan,
                    'entitlement_reuse',
                    (string) $existingEntitlement->billing_interval,
                    [
                        'started_at' => now(),
                        'renews_at' => $existingEntitlement->expires_at,
                        'stripe_customer_id' => $existingEntitlement->stripe_customer_id,
                        'stripe_subscription_id' => $existingEntitlement->stripe_subscription_id,
                        'stripe_checkout_session_id' => $existingEntitlement->stripe_checkout_session_id,
                    ]
                );

                return response()->json([
                    'status' => 'success',
                    'mode' => 'entitlement_reuse',
                    'message' => 'Switched back to your paid plan using remaining prepaid time.',
                    'subscription' => $this->subscriptionService->serializeSubscription($subscription),
                    'entitlement_expires_at' => $existingEntitlement->expires_at?->toISOString(),
                ]);
            }
        }

        if ($planPrice <= 0) {
            $activeSubscription = UserSubscription::query()
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->with('plan')
                ->orderByDesc('renews_at')
                ->orderByDesc('started_at')
                ->first();

            if (
                $activeSubscription &&
                $activeSubscription->plan &&
                (float) $activeSubscription->plan->price_monthly > 0 &&
                $activeSubscription->renews_at &&
                Carbon::parse($activeSubscription->renews_at)->greaterThan(now())
            ) {
                $activeInterval = (string) ($activeSubscription->billing_interval ?? 'monthly');
                $activeInterval = $activeInterval === 'annual' ? 'annual' : 'monthly';

                $this->subscriptionService->createOrRefreshEntitlement(
                    $user,
                    $activeSubscription->plan,
                    $activeInterval,
                    [
                        'source' => 'plan_switch_snapshot',
                        'starts_at' => $activeSubscription->started_at ?? now(),
                        'expires_at' => $activeSubscription->renews_at,
                        'stripe_customer_id' => $activeSubscription->stripe_customer_id,
                        'stripe_subscription_id' => $activeSubscription->stripe_subscription_id,
                        'stripe_checkout_session_id' => $activeSubscription->stripe_checkout_session_id,
                        'metadata' => [
                            'reason' => 'switched_to_free',
                        ],
                    ]
                );
            }

            $subscription = $this->subscriptionService->activatePlan(
                $user,
                $plan,
                'free_plan',
                $billingPeriod
            );

            return response()->json([
                'status' => 'success',
                'mode' => 'free_plan',
                'message' => 'Your plan has been updated.',
                'subscription' => $this->subscriptionService->serializeSubscription($subscription),
            ]);
        }

        $session = $this->stripeBillingService->createCheckoutSession($user, $plan, $billingPeriod);
        if (!($session['success'] ?? false)) {
            return response()->json([
                'status' => 'error',
                'message' => $session['message'] ?? 'Unable to create Stripe checkout session.',
            ], (int) ($session['status_code'] ?? 422));
        }

        return response()->json([
            'status' => 'success',
            'mode' => 'stripe_checkout',
            'message' => 'Redirecting to secure Stripe checkout.',
            'session_id' => $session['session_id'],
            'checkout_url' => $session['checkout_url'],
        ]);
    }

    public function stripeWebhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signatureHeader = $request->header('Stripe-Signature');

        if (!$this->stripeBillingService->verifyWebhookSignature($payload, $signatureHeader)) {
            Log::warning('Stripe webhook signature verification failed.', [
                'has_signature_header' => !empty($signatureHeader),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Invalid Stripe webhook signature.',
            ], 400);
        }

        $event = $this->stripeBillingService->decodeWebhookEvent($payload);
        if (!$event) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid webhook payload.',
            ], 400);
        }

        $eventType = (string) ($event['type'] ?? '');
        $eventObject = data_get($event, 'data.object');
        if (!is_array($eventObject)) {
            return response()->json(['status' => 'success']);
        }

        if ($eventType === 'checkout.session.completed') {
            $this->handleCheckoutSessionCompleted($eventObject);
        }

        if ($eventType === 'customer.subscription.updated' || $eventType === 'customer.subscription.deleted') {
            $this->subscriptionService->syncStripeSubscriptionStatus($eventObject);
        }

        return response()->json(['status' => 'success']);
    }

    public function confirmCheckoutSession(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|integer|exists:users,id',
                'session_id' => 'required|string|min:10',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid checkout confirmation payload.',
                'errors' => $e->errors(),
            ], 422);
        }

        $user = User::query()->find((int) $validated['user_id']);
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found.',
            ], 404);
        }

        $sessionResult = $this->stripeBillingService->retrieveCheckoutSession(
            (string) $validated['session_id'],
            ['subscription']
        );

        if (!($sessionResult['success'] ?? false)) {
            return response()->json([
                'status' => 'error',
                'message' => $sessionResult['message'] ?? 'Unable to verify Stripe checkout session.',
            ], (int) ($sessionResult['status_code'] ?? 422));
        }

        $sessionObject = $sessionResult['session'] ?? null;
        if (!is_array($sessionObject)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Stripe checkout session response is invalid.',
            ], 502);
        }

        $resolvedSubscription = $this->handleCheckoutSessionCompleted($sessionObject, $user);
        if (!$resolvedSubscription) {
            return response()->json([
                'status' => 'error',
                'message' => 'Checkout payment is not ready for activation yet.',
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Your plan has been upgraded successfully.',
            'subscription' => $this->subscriptionService->serializeSubscription($resolvedSubscription),
        ]);
    }

    /**
     * @param array<string, mixed> $sessionObject
     */
    private function handleCheckoutSessionCompleted(array $sessionObject, ?User $expectedUser = null): ?UserSubscription
    {
        if (($sessionObject['mode'] ?? null) !== 'subscription') {
            return null;
        }

        $sessionStatus = (string) ($sessionObject['status'] ?? '');
        $paymentStatus = (string) ($sessionObject['payment_status'] ?? '');
        $isPaid = in_array($paymentStatus, ['paid', 'no_payment_required'], true);
        if ($sessionStatus !== 'complete' || !$isPaid) {
            return null;
        }

        $metadata = $sessionObject['metadata'] ?? [];
        if (!is_array($metadata)) {
            $metadata = [];
        }

        $user = $this->resolveUserFromStripeData($metadata, $sessionObject);
        $plan = $this->resolvePlanFromStripeMetadata($metadata);
        if (!$user || !$plan) {
            return null;
        }

        if ($expectedUser && (int) $expectedUser->id !== (int) $user->id) {
            return null;
        }

        $billingPeriod = (string) ($metadata['billing_period'] ?? 'monthly');
        $billingPeriod = $billingPeriod === 'annual' ? 'annual' : 'monthly';

        $subscriptionObject = $this->resolveStripeSubscriptionObject($sessionObject);
        $periodStart = $this->resolveStripePeriodStart($subscriptionObject) ?? now();
        $periodEnd = $this->resolveStripePeriodEnd($subscriptionObject, $billingPeriod)
            ?? ($billingPeriod === 'annual' ? now()->copy()->addYear() : now()->copy()->addMonth());

        $subscription = $this->subscriptionService->activatePlan(
            $user,
            $plan,
            'stripe',
            $billingPeriod,
            [
                'stripe_customer_id' => is_string($sessionObject['customer'] ?? null)
                    ? $sessionObject['customer']
                    : null,
                'stripe_subscription_id' => is_string($sessionObject['subscription'] ?? null)
                    ? $sessionObject['subscription']
                    : null,
                'stripe_checkout_session_id' => is_string($sessionObject['id'] ?? null)
                    ? $sessionObject['id']
                    : null,
                'last_payment_at' => now(),
                'started_at' => $periodStart,
                'renews_at' => $periodEnd,
            ]
        );

        $this->subscriptionService->createOrRefreshEntitlement(
            $user,
            $plan,
            $billingPeriod,
            [
                'source' => 'stripe',
                'starts_at' => $periodStart,
                'expires_at' => $periodEnd,
                'stripe_customer_id' => is_string($sessionObject['customer'] ?? null)
                    ? $sessionObject['customer']
                    : null,
                'stripe_subscription_id' => is_string($sessionObject['subscription'] ?? null)
                    ? $sessionObject['subscription']
                    : null,
                'stripe_checkout_session_id' => is_string($sessionObject['id'] ?? null)
                    ? $sessionObject['id']
                    : null,
                'metadata' => [
                    'payment_status' => $sessionObject['payment_status'] ?? null,
                    'session_status' => $sessionObject['status'] ?? null,
                ],
            ]
        );

        $this->subscriptionService->recordPaymentTransaction(
            $user,
            $plan,
            $subscription,
            [
                'provider' => 'stripe',
                'transaction_type' => 'charge',
                'provider_transaction_id' => is_string($sessionObject['payment_intent'] ?? null)
                    ? $sessionObject['payment_intent']
                    : null,
                'provider_session_id' => is_string($sessionObject['id'] ?? null)
                    ? $sessionObject['id']
                    : null,
                'provider_subscription_id' => is_string($sessionObject['subscription'] ?? null)
                    ? $sessionObject['subscription']
                    : null,
                'provider_customer_id' => is_string($sessionObject['customer'] ?? null)
                    ? $sessionObject['customer']
                    : null,
                'billing_interval' => $billingPeriod,
                'amount' => ((int) ($sessionObject['amount_total'] ?? 0)) / 100,
                'currency' => strtoupper((string) ($sessionObject['currency'] ?? 'USD')),
                'status' => 'paid',
                'paid_at' => now(),
                'metadata' => [
                    'session_id' => $sessionObject['id'] ?? null,
                    'mode' => $sessionObject['mode'] ?? null,
                    'livemode' => $sessionObject['livemode'] ?? null,
                    'entitlement_starts_at' => $periodStart->toISOString(),
                    'entitlement_expires_at' => $periodEnd->toISOString(),
                ],
            ]
        );

        return $subscription;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $sessionObject
     */
    private function resolveUserFromStripeData(array $metadata, array $sessionObject): ?User
    {
        $userId = $metadata['user_id'] ?? null;
        if (is_string($userId) || is_int($userId)) {
            $user = User::query()->find((int) $userId);
            if ($user) {
                return $user;
            }
        }

        $email = $sessionObject['customer_email'] ?? null;
        if (is_string($email) && trim($email) !== '') {
            return User::query()->where('email', trim($email))->first();
        }

        return null;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function resolvePlanFromStripeMetadata(array $metadata): ?Plan
    {
        $planId = $metadata['plan_id'] ?? null;
        if (!is_string($planId) && !is_int($planId)) {
            return null;
        }

        return Plan::query()->where('is_active', true)->find((int) $planId);
    }

    /**
     * @param array<string, mixed> $sessionObject
     * @return array<string, mixed>|null
     */
    private function resolveStripeSubscriptionObject(array $sessionObject): ?array
    {
        $subscription = $sessionObject['subscription'] ?? null;
        if (is_array($subscription)) {
            return $subscription;
        }

        if (!is_string($subscription) || trim($subscription) === '') {
            return null;
        }

        $response = $this->stripeBillingService->retrieveSubscription($subscription);
        if (!($response['success'] ?? false)) {
            Log::warning('Failed to retrieve Stripe subscription while finalizing checkout.', [
                'subscription_id' => $subscription,
                'message' => $response['message'] ?? null,
            ]);
            return null;
        }

        $subscriptionObject = $response['subscription'] ?? null;
        return is_array($subscriptionObject) ? $subscriptionObject : null;
    }

    /**
     * @param array<string, mixed>|null $subscriptionObject
     */
    private function resolveStripePeriodStart(?array $subscriptionObject): ?Carbon
    {
        if (!is_array($subscriptionObject)) {
            return null;
        }

        $start = data_get($subscriptionObject, 'current_period_start');
        if (!is_numeric($start)) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $start);
    }

    /**
     * @param array<string, mixed>|null $subscriptionObject
     */
    private function resolveStripePeriodEnd(?array $subscriptionObject, string $billingPeriod): ?Carbon
    {
        if (is_array($subscriptionObject)) {
            $end = data_get($subscriptionObject, 'current_period_end');
            if (is_numeric($end)) {
                return Carbon::createFromTimestamp((int) $end);
            }
        }

        return $billingPeriod === 'annual'
            ? now()->copy()->addYear()
            : now()->copy()->addMonth();
    }
}
