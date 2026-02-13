<?php

namespace App\Http\Controllers;

use App\Models\CompanyPlanAllocation;
use App\Models\Plan;
use App\Services\CompanyPlanService;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminPlanController extends Controller
{
    public function __construct(
        private PlanService $planService,
        private CompanyPlanService $companyPlanService
    ) {
    }

    public function createCustomPlan(Request $request): JsonResponse
    {
        if ($unauthorized = $this->authorizeAdmin($request)) {
            return $unauthorized;
        }

        try {
            $validated = $request->validate([
                'name' => 'required|string|max:100|unique:plans,name',
                'description' => 'nullable|string|max:5000',
                'price_monthly' => 'nullable|numeric|min:0',
                'price_yearly' => 'nullable|numeric|min:0',
                'is_active' => 'nullable|boolean',
                'features' => 'nullable|array',
                'features.*' => 'nullable|integer|min:0',
            ]);

            $plan = Plan::query()->create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'price_monthly' => $validated['price_monthly'] ?? 0,
                'price_yearly' => $validated['price_yearly'] ?? 0,
                'is_active' => $validated['is_active'] ?? true,
                'is_custom' => true,
                'contact_sales' => true,
            ]);

            $features = $validated['features'] ?? [
                PlanService::FEATURE_MAX_AUDITS_PER_MONTH => 10000,
                PlanService::FEATURE_CONCURRENT_AUDITS_ALLOWED => 25,
            ];

            foreach ($features as $featureName => $limitValue) {
                if (!is_string($featureName) || trim($featureName) === '') {
                    continue;
                }

                $plan->features()->updateOrCreate(
                    ['feature_name' => trim($featureName)],
                    ['limit_value' => $limitValue]
                );
            }

            $plan->load('features');

            return response()->json([
                'status' => 'success',
                'message' => 'Custom plan created successfully.',
                'plan' => $this->planService->serializePlan($plan),
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid custom-plan payload.',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function upsertCompanyAllocation(Request $request): JsonResponse
    {
        if ($unauthorized = $this->authorizeAdmin($request)) {
            return $unauthorized;
        }

        try {
            $validated = $request->validate([
                'company_name' => 'required|string|max:255',
                'plan_id' => 'required|integer|exists:plans,id',
                'is_active' => 'nullable|boolean',
                'notes' => 'nullable|string|max:5000',
                'allocated_by' => 'nullable|string|max:255',
            ]);

            $allocation = $this->companyPlanService->upsertCompanyAllocation(
                $validated['company_name'],
                (int) $validated['plan_id'],
                (bool) ($validated['is_active'] ?? true),
                $validated['notes'] ?? null,
                $validated['allocated_by'] ?? null
            );

            $allocation->load('plan.features');

            return response()->json([
                'status' => 'success',
                'message' => 'Company plan allocation saved.',
                'allocation' => $this->companyPlanService->serializeAllocation($allocation, $this->planService),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid company allocation payload.',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function companyAllocations(Request $request): JsonResponse
    {
        if ($unauthorized = $this->authorizeAdmin($request)) {
            return $unauthorized;
        }

        $query = CompanyPlanAllocation::query()->with('plan.features')->orderBy('company_name');

        $companyName = $request->query('company_name');
        if (is_string($companyName) && trim($companyName) !== '') {
            $query->where(
                'company_key',
                $this->companyPlanService->normalizeCompanyKey($companyName)
            );
        }

        $allocations = $query->get();

        return response()->json([
            'status' => 'success',
            'total' => $allocations->count(),
            'allocations' => $allocations
                ->map(fn (CompanyPlanAllocation $allocation) =>
                    $this->companyPlanService->serializeAllocation($allocation, $this->planService)
                )
                ->values(),
        ]);
    }

    private function authorizeAdmin(Request $request): ?JsonResponse
    {
        $expectedKey = (string) config('plans.admin_key', '');
        $providedKey = (string) $request->header('X-Admin-Key', '');

        if ($expectedKey === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Admin plan endpoints are not configured. Set PLANS_ADMIN_KEY first.',
            ], 503);
        }

        if (!hash_equals($expectedKey, $providedKey)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized admin request.',
            ], 401);
        }

        return null;
    }
}
