<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditRun;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\SubscriptionService;
use App\Services\UsageService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminUserController extends Controller
{
    public function __construct(
        private SubscriptionService $subscriptionService,
        private UsageService $usageService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'limit' => 'nullable|integer|min:1|max:200',
                'search' => 'nullable|string|max:255',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid admin user list parameters.',
                'errors' => $e->errors(),
            ], 422);
        }

        $limit = (int) ($validated['limit'] ?? 50);

        $query = User::query()
            ->where('role', 'user')
            ->withCount('auditRuns')
            ->withMax('auditRuns', 'created_at')
            ->with([
                'subscriptions' => function ($subscriptionQuery): void {
                    $subscriptionQuery
                        ->with('plan.features')
                        ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                        ->orderByDesc('renews_at')
                        ->orderByDesc('started_at');
                },
            ])
            ->orderByDesc('created_at');

        if (!empty($validated['search'])) {
            $search = trim((string) $validated['search']);
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('company', 'like', "%{$search}%");
            });
        }

        $users = $query->limit($limit)->get();

        return response()->json([
            'status' => 'success',
            'total' => $users->count(),
            'users' => $users->map(fn (User $user): array => $this->serializeUserListItem($user))->values(),
        ]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        if ($user->role !== 'user') {
            return response()->json([
                'status' => 'error',
                'message' => 'Only customer user accounts can be viewed here.',
            ], 404);
        }

        try {
            $validated = $request->validate([
                'audits_limit' => 'nullable|integer|min:1|max:200',
                'auth_events_limit' => 'nullable|integer|min:1|max:200',
                'payments_limit' => 'nullable|integer|min:1|max:200',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid admin user detail parameters.',
                'errors' => $e->errors(),
            ], 422);
        }

        $auditsLimit = (int) ($validated['audits_limit'] ?? 50);
        $authEventsLimit = (int) ($validated['auth_events_limit'] ?? 50);
        $paymentsLimit = (int) ($validated['payments_limit'] ?? 50);

        $user->load([
            'auditRuns' => function ($query) use ($auditsLimit): void {
                $query->orderByDesc('created_at')->limit($auditsLimit);
            },
            'authEvents' => function ($query) use ($authEventsLimit): void {
                $query->orderByDesc('created_at')->limit($authEventsLimit);
            },
            'subscriptions' => function ($query): void {
                $query
                    ->with('plan.features')
                    ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                    ->orderByDesc('renews_at')
                    ->orderByDesc('started_at');
            },
            'paymentTransactions' => function ($query) use ($paymentsLimit): void {
                $query
                    ->with('plan.features')
                    ->orderByDesc('paid_at')
                    ->orderByDesc('id')
                    ->limit($paymentsLimit);
            },
        ]);

        $currentSubscription = $this->resolveCurrentSubscription($user);

        return response()->json([
            'status' => 'success',
            'user' => [
                ...$this->serializeUserCore($user),
                'current_subscription' => $currentSubscription
                    ? $this->subscriptionService->serializeSubscription($currentSubscription)
                    : null,
                'usage' => $this->usageService->getUsageStats($user),
                'subscription_history' => $user->subscriptions
                    ->map(fn (UserSubscription $subscription): array => $this->subscriptionService->serializeSubscription($subscription))
                    ->values(),
                'audit_history' => $user->auditRuns
                    ->map(fn (AuditRun $audit): array => $this->serializeAuditRunSummary($audit))
                    ->values(),
                'auth_events' => $user->authEvents
                    ->map(fn ($event): array => [
                        'id' => $event->id,
                        'event_type' => $event->event_type,
                        'provider' => $event->provider,
                        'ip_address' => $event->ip_address,
                        'user_agent' => $event->user_agent,
                        'metadata' => $event->metadata,
                        'created_at' => $event->created_at?->toISOString(),
                    ])
                    ->values(),
                'payment_history' => $user->paymentTransactions
                    ->map(fn ($payment): array => $this->subscriptionService->serializePaymentTransaction($payment))
                    ->values(),
            ],
        ]);
    }

    public function audit(User $user, AuditRun $auditRun): JsonResponse
    {
        if ($user->role !== 'user') {
            return response()->json([
                'status' => 'error',
                'message' => 'Only customer user accounts can be viewed here.',
            ], 404);
        }

        if ((int) $auditRun->user_id !== (int) $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Audit not found for the selected user.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'user_id' => $user->id,
            'audit' => $this->serializeAuditRunDetail($auditRun),
        ]);
    }

    private function serializeUserListItem(User $user): array
    {
        $currentSubscription = $this->resolveCurrentSubscription($user);

        return [
            ...$this->serializeUserCore($user),
            'audit_runs_count' => (int) ($user->audit_runs_count ?? 0),
            'latest_audit_at' => $this->toIsoString($user->audit_runs_max_created_at ?? null),
            'current_subscription' => $currentSubscription
                ? $this->subscriptionService->serializeSubscription($currentSubscription)
                : null,
        ];
    }

    private function serializeUserCore(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'registration_provider' => $user->registration_provider,
            'avatar_url' => $user->avatar_url,
            'phone' => $user->phone,
            'company' => $user->company,
            'industry' => $user->industry,
            'company_size' => $user->company_size,
            'website' => $user->website,
            'location' => $user->location,
            'notification_preferences' => $user->notification_preferences,
            'last_login_at' => $user->last_login_at?->toISOString(),
            'last_login_provider' => $user->last_login_provider,
            'created_at' => $user->created_at?->toISOString(),
        ];
    }

    private function resolveCurrentSubscription(User $user): ?UserSubscription
    {
        $subscriptions = $user->subscriptions;
        if ($subscriptions->isEmpty()) {
            return null;
        }

        /** @var UserSubscription|null $subscription */
        $subscription = $subscriptions->first();
        return $subscription;
    }

    private function serializeAuditRunSummary(AuditRun $audit): array
    {
        return [
            'id' => $audit->id,
            'status' => $audit->status,
            'business_name' => $audit->business_name,
            'website' => $audit->website,
            'location' => $audit->location,
            'industry' => $audit->industry,
            'reputation_score' => $audit->reputation_score,
            'scan_date' => $audit->scan_date?->toISOString(),
            'created_at' => $audit->created_at?->toISOString(),
            'error_code' => $audit->error_code,
            'error_message' => $audit->error_message,
        ];
    }

    private function serializeAuditRunDetail(AuditRun $audit): array
    {
        return [
            ...$this->serializeAuditRunSummary($audit),
            'request_payload' => $audit->request_payload,
            'response_payload' => $audit->response_payload,
            'scan_response' => $audit->status === 'success' ? $audit->response_payload : null,
        ];
    }

    private function toIsoString(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toISOString();
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->toISOString();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
