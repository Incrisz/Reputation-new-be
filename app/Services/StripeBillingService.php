<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Http;

class StripeBillingService
{
    private const STRIPE_API_BASE = 'https://api.stripe.com/v1';
    private const WEBHOOK_TOLERANCE_SECONDS = 300;

    /**
     * @return array{success: bool, message?: string, status_code?: int, session_id?: string, checkout_url?: string}
     */
    public function createCheckoutSession(User $user, Plan $plan, string $billingPeriod = 'monthly'): array
    {
        $secretKey = $this->getSecretKey();
        if ($secretKey === '') {
            return [
                'success' => false,
                'status_code' => 503,
                'message' => 'Stripe is not configured on the server.',
            ];
        }

        $billingPeriod = $billingPeriod === 'annual' ? 'annual' : 'monthly';
        $amount = $billingPeriod === 'annual'
            ? (float) $plan->price_yearly
            : (float) $plan->price_monthly;

        if ($amount <= 0) {
            return [
                'success' => false,
                'status_code' => 422,
                'message' => 'This plan does not require a Stripe checkout.',
            ];
        }

        $interval = $billingPeriod === 'annual' ? 'year' : 'month';
        $successUrl = $this->withCheckoutSessionPlaceholder((string) config('services.stripe.success_url'));
        $cancelUrl = (string) config('services.stripe.cancel_url');

        $productData = [
            'name' => "{$plan->name} Plan",
        ];
        if (!empty($plan->description)) {
            $productData['description'] = (string) $plan->description;
        }

        $payload = [
            'mode' => 'subscription',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $user->id,
            'customer_email' => (string) $user->email,
            'metadata' => [
                'user_id' => (string) $user->id,
                'plan_id' => (string) $plan->id,
                'billing_period' => $billingPeriod,
            ],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'usd',
                    'unit_amount' => (int) round($amount * 100),
                    'recurring' => [
                        'interval' => $interval,
                    ],
                    'product_data' => $productData,
                ],
            ]],
            'subscription_data' => [
                'metadata' => [
                    'user_id' => (string) $user->id,
                    'plan_id' => (string) $plan->id,
                    'billing_period' => $billingPeriod,
                ],
            ],
        ];

        $response = Http::withBasicAuth($secretKey, '')
            ->asForm()
            ->acceptJson()
            ->post(self::STRIPE_API_BASE . '/checkout/sessions', $payload);

        if (!$response->successful()) {
            return [
                'success' => false,
                'status_code' => $response->status(),
                'message' => $this->extractStripeErrorMessage(
                    $response->json(),
                    'Unable to create Stripe checkout session.'
                ),
            ];
        }

        $data = $response->json();
        if (!is_array($data) || !isset($data['id'], $data['url'])) {
            return [
                'success' => false,
                'status_code' => 502,
                'message' => 'Stripe checkout session response is invalid.',
            ];
        }

        return [
            'success' => true,
            'session_id' => (string) $data['id'],
            'checkout_url' => (string) $data['url'],
        ];
    }

    /**
     * @param array<int, string> $expand
     * @return array{success: bool, message?: string, status_code?: int, session?: array<string, mixed>}
     */
    public function retrieveCheckoutSession(string $sessionId, array $expand = []): array
    {
        $secretKey = $this->getSecretKey();
        if ($secretKey === '') {
            return [
                'success' => false,
                'status_code' => 503,
                'message' => 'Stripe is not configured on the server.',
            ];
        }

        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return [
                'success' => false,
                'status_code' => 422,
                'message' => 'A Stripe checkout session id is required.',
            ];
        }

        $request = Http::withBasicAuth($secretKey, '')->acceptJson();
        $url = self::STRIPE_API_BASE . '/checkout/sessions/' . $sessionId;
        if (!empty($expand)) {
            $expandQuery = implode(
                '&',
                array_map(
                    fn (string $value): string => 'expand[]=' . rawurlencode($value),
                    $expand
                )
            );
            $url .= '?' . $expandQuery;
        }

        $response = $request->get($url);

        if (!$response->successful()) {
            return [
                'success' => false,
                'status_code' => $response->status(),
                'message' => $this->extractStripeErrorMessage(
                    $response->json(),
                    'Unable to load Stripe checkout session.'
                ),
            ];
        }

        $data = $response->json();
        if (!is_array($data) || !isset($data['id'])) {
            return [
                'success' => false,
                'status_code' => 502,
                'message' => 'Stripe checkout session response is invalid.',
            ];
        }

        return [
            'success' => true,
            'session' => $data,
        ];
    }

    public function verifyWebhookSignature(string $payload, ?string $signatureHeader): bool
    {
        $webhookSecret = (string) config('services.stripe.webhook_secret');
        if ($webhookSecret === '' || !$signatureHeader) {
            return false;
        }

        $parts = $this->parseStripeSignature($signatureHeader);
        $timestamp = isset($parts['t']) ? (int) $parts['t'] : 0;
        if ($timestamp <= 0 || !isset($parts['v1'])) {
            return false;
        }

        if (abs(time() - $timestamp) > self::WEBHOOK_TOLERANCE_SECONDS) {
            return false;
        }

        $signedPayload = $timestamp . '.' . $payload;
        $expectedSignature = hash_hmac('sha256', $signedPayload, $webhookSecret);

        foreach ($parts['v1'] as $providedSignature) {
            if (is_string($providedSignature) && hash_equals($expectedSignature, $providedSignature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function decodeWebhookEvent(string $payload): ?array
    {
        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function extractStripeErrorMessage(?array $payload, string $fallback): string
    {
        if (is_array($payload)) {
            $errorMessage = data_get($payload, 'error.message');
            if (is_string($errorMessage) && $errorMessage !== '') {
                return $errorMessage;
            }
        }

        return $fallback;
    }

    private function withCheckoutSessionPlaceholder(string $url): string
    {
        if ($url === '') {
            return '';
        }

        if (str_contains($url, '{CHECKOUT_SESSION_ID}')) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . 'session_id={CHECKOUT_SESSION_ID}';
    }

    private function getSecretKey(): string
    {
        return (string) config('services.stripe.secret_key');
    }

    /**
     * @return array<string, mixed>
     */
    private function parseStripeSignature(string $signatureHeader): array
    {
        $result = [];
        $pairs = array_filter(array_map('trim', explode(',', $signatureHeader)));

        foreach ($pairs as $pair) {
            $segments = explode('=', $pair, 2);
            if (count($segments) !== 2) {
                continue;
            }

            [$key, $value] = $segments;
            if ($key === 'v1') {
                if (!isset($result['v1'])) {
                    $result['v1'] = [];
                }
                $result['v1'][] = $value;
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
