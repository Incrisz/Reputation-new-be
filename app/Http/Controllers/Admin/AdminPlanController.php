<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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

    public function index(Request $request): JsonResponse
    {
        $showInactive = filter_var($request->query('show_inactive', false), FILTER_VALIDATE_BOOL);

        $plansQuery = Plan::query()
            ->with('features')
            ->orderBy('price_monthly');

        if (!$showInactive) {
            $plansQuery->where('is_active', true);
        }

        $plans = $plansQuery->get();

        return response()->json([
            'status' => 'success',
            'total' => $plans->count(),
            'plans' => $plans
                ->map(fn (Plan $plan): array => $this->planService->serializePlan($plan))
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:100|unique:plans,name',
                'description' => 'nullable|string|max:5000',
                'price_monthly' => 'nullable|numeric|min:0',
                'price_yearly' => 'nullable|numeric|min:0',
                'is_active' => 'nullable|boolean',
                'is_custom' => 'nullable|boolean',
                'contact_sales' => 'nullable|boolean',
                'features' => 'nullable|array',
                'features.*' => 'nullable|integer|min:0',
            ]);

            $plan = Plan::query()->create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'price_monthly' => $validated['price_monthly'] ?? 0,
                'price_yearly' => $validated['price_yearly'] ?? 0,
                'is_active' => $validated['is_active'] ?? true,
                'is_custom' => $validated['is_custom'] ?? false,
                'contact_sales' => $validated['contact_sales'] ?? false,
            ]);

            $this->savePlanFeatures($plan, $validated['features'] ?? []);
            $plan->load('features');

            return response()->json([
                'status' => 'success',
                'message' => 'Plan created successfully.',
                'plan' => $this->planService->serializePlan($plan),
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid plan payload.',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function update(Request $request, Plan $plan): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:100|unique:plans,name,'.$plan->id,
                'description' => 'sometimes|nullable|string|max:5000',
                'price_monthly' => 'sometimes|nullable|numeric|min:0',
                'price_yearly' => 'sometimes|nullable|numeric|min:0',
                'is_active' => 'sometimes|boolean',
                'is_custom' => 'sometimes|boolean',
                'contact_sales' => 'sometimes|boolean',
                'features' => 'sometimes|array',
                'features.*' => 'nullable|integer|min:0',
            ]);

            $plan->fill([
                'name' => array_key_exists('name', $validated) ? $validated['name'] : $plan->name,
                'description' => array_key_exists('description', $validated) ? $validated['description'] : $plan->description,
                'price_monthly' => array_key_exists('price_monthly', $validated) ? ($validated['price_monthly'] ?? 0) : $plan->price_monthly,
                'price_yearly' => array_key_exists('price_yearly', $validated) ? ($validated['price_yearly'] ?? 0) : $plan->price_yearly,
                'is_active' => array_key_exists('is_active', $validated) ? $validated['is_active'] : $plan->is_active,
                'is_custom' => array_key_exists('is_custom', $validated) ? $validated['is_custom'] : $plan->is_custom,
                'contact_sales' => array_key_exists('contact_sales', $validated) ? $validated['contact_sales'] : $plan->contact_sales,
            ])->save();

            if (array_key_exists('features', $validated)) {
                $this->savePlanFeatures($plan, $validated['features'] ?? [], true);
            }

            $plan->load('features');

            return response()->json([
                'status' => 'success',
                'message' => 'Plan updated successfully.',
                'plan' => $this->planService->serializePlan($plan),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid plan update payload.',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function destroy(Plan $plan): JsonResponse
    {
        if ($plan->subscriptions()->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'This plan has subscriptions and cannot be deleted.',
            ], 422);
        }

        if ($plan->companyAllocations()->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'This plan is allocated to a company and cannot be deleted.',
            ], 422);
        }

        $plan->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Plan deleted successfully.',
        ]);
    }

    public function createCustomPlan(Request $request): JsonResponse
    {
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

            $this->savePlanFeatures($plan, $validated['features'] ?? [
                PlanService::FEATURE_MAX_AUDITS_PER_MONTH => 10000,
                PlanService::FEATURE_CONCURRENT_AUDITS_ALLOWED => 25,
            ]);

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

    /**
     * @param  array<string, int|null>  $features
     */
    private function savePlanFeatures(Plan $plan, array $features, bool $replaceExisting = false): void
    {
        if ($replaceExisting) {
            $plan->features()->delete();
        }

        foreach ($features as $featureName => $limitValue) {
            if (!is_string($featureName) || trim($featureName) === '') {
                continue;
            }

            $plan->features()->updateOrCreate(
                ['feature_name' => trim($featureName)],
                ['limit_value' => $limitValue]
            );
        }
    }
}
