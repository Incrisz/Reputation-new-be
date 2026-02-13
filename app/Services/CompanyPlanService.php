<?php

namespace App\Services;

use App\Models\CompanyPlanAllocation;
use App\Models\Plan;

class CompanyPlanService
{
    public function normalizeCompanyKey(string $companyName): string
    {
        $normalized = strtolower(trim($companyName));
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        return (string) $normalized;
    }

    public function getActiveAllocationForCompany(?string $companyName): ?CompanyPlanAllocation
    {
        if (!$companyName || trim($companyName) === '') {
            return null;
        }

        $companyKey = $this->normalizeCompanyKey($companyName);

        return CompanyPlanAllocation::query()
            ->where('company_key', $companyKey)
            ->where('is_active', true)
            ->with('plan.features')
            ->first();
    }

    public function getAllocatedPlanForCompany(?string $companyName): ?Plan
    {
        $allocation = $this->getActiveAllocationForCompany($companyName);
        if (!$allocation || !$allocation->plan || !$allocation->plan->is_active) {
            return null;
        }

        return $allocation->plan;
    }

    public function upsertCompanyAllocation(
        string $companyName,
        int $planId,
        bool $isActive = true,
        ?string $notes = null,
        ?string $allocatedBy = null
    ): CompanyPlanAllocation {
        $companyName = trim($companyName);
        $companyKey = $this->normalizeCompanyKey($companyName);

        return CompanyPlanAllocation::query()->updateOrCreate(
            ['company_key' => $companyKey],
            [
                'company_name' => $companyName,
                'plan_id' => $planId,
                'is_active' => $isActive,
                'notes' => $notes,
                'allocated_by' => $allocatedBy,
            ]
        );
    }

    public function serializeAllocation(CompanyPlanAllocation $allocation, PlanService $planService): array
    {
        return [
            'id' => $allocation->id,
            'company_name' => $allocation->company_name,
            'company_key' => $allocation->company_key,
            'is_active' => (bool) $allocation->is_active,
            'notes' => $allocation->notes,
            'allocated_by' => $allocation->allocated_by,
            'plan' => $allocation->plan
                ? $planService->serializePlan($allocation->plan)
                : null,
            'created_at' => $allocation->created_at?->toISOString(),
            'updated_at' => $allocation->updated_at?->toISOString(),
        ];
    }
}
