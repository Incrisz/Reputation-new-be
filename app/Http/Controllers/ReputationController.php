<?php

namespace App\Http\Controllers;

use App\Jobs\RunAuditScanJob;
use App\Models\AuditRun;
use App\Models\User;
use App\Services\GooglePlacesService;
use App\Services\RestrictionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * @OA\Info(
 *     version="1.0.0",
 *     title="Reputation AI - Business Reputation Scanner API",
 *     description="Comprehensive business reputation analysis using web search, sentiment analysis, and AI recommendations",
 *     contact=@OA\Contact(
 *         email="support@reputationai.com"
 *     ),
 *     license=@OA\License(
 *         name="Apache 2.0",
 *         url="https://www.apache.org/licenses/LICENSE-2.0.html"
 *     )
 * )
 * @OA\Server(
 *     url=L5_SWAGGER_CONST_HOST,
 *     description="API Server"
 * )
 * @OA\SecurityScheme(
 *     type="http",
 *     name="Authorization",
 *     in="header",
 *     scheme="bearer",
 *     securityScheme="bearer"
 * )
 */
class ReputationController extends Controller
{
    private GooglePlacesService $placesService;
    private RestrictionService $restrictionService;

    public function __construct(
        GooglePlacesService $placesService,
        RestrictionService $restrictionService
    )
    {
        $this->placesService = $placesService;
        $this->restrictionService = $restrictionService;
    }

    /**
     * Scan business reputation
     * 
     * @OA\Post(
     *     path="/api/reputation/scan",
     *     operationId="scanReputation",
     *     tags={"Reputation Scan"},
     *     summary="Scan and analyze business reputation",
     *     description="Performs comprehensive business reputation analysis. If place_id is missing and business_name is provided, returns selection_required with candidate businesses.",
     *     @OA\RequestBody(
     *         required=true,
     *         description="Business identification data",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="website",
     *                 type="string",
     *                 example="apple.com",
     *                 description="Optional business website URL"
     *             ),
     *             @OA\Property(
     *                 property="phone",
     *                 type="string",
     *                 example="+1-555-123-4567",
     *                 description="Optional business phone number"
     *             ),
     *             @OA\Property(
     *                 property="location",
     *                 type="string",
     *                 example="San Francisco, CA",
     *                 description="Optional business location (recommended for better matching)"
     *             ),
     *             @OA\Property(
     *                 property="country",
     *                 type="string",
     *                 example="us",
     *                 description="Optional 2-letter country code for Places and search"
     *             ),
     *             @OA\Property(
     *                 property="business_name",
     *                 type="string",
     *                 example="Apple Inc",
     *                 description="Business name (required if no website or phone+location)"
     *             ),
     *             @OA\Property(
     *                 property="place_id",
     *                 type="string",
     *                 example="ChIJ2eUgeAK6j4ARbn5u_wAGqWA",
     *                 description="Optional Google Places place_id from the selection_required response"
     *             ),
     *             @OA\Property(
     *                 property="skip_places",
     *                 type="boolean",
     *                 example=false,
     *                 description="Set true to skip Google Places selection when no match exists"
     *             ),
     *             @OA\Property(
     *                 property="industry",
     *                 type="string",
     *                 example="Technology",
     *                 description="Optional industry classification"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful reputation scan",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="candidates", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="total", type="integer", example=3),
     *             @OA\Property(property="business_name", type="string", example="Apple Inc"),
     *             @OA\Property(property="verified_website", type="string", example="apple.com"),
     *             @OA\Property(property="scan_date", type="string", format="date-time"),
     *             @OA\Property(
     *                 property="results",
     *                 type="object",
     *                 @OA\Property(property="reputation_score", type="number", format="float", example=87.5),
     *                 @OA\Property(property="sentiment_breakdown", type="object"),
     *                 @OA\Property(property="key_themes", type="array", @OA\Items(type="string")),
     *                 @OA\Property(property="mentions", type="array", @OA\Items(type="object")),
     *                 @OA\Property(property="recommendations", type="array", @OA\Items(type="object")),
     *                 @OA\Property(
     *                     property="online_profile",
     *                     type="object",
     *                     @OA\Property(property="found", type="boolean"),
     *                     @OA\Property(property="visibility_score", type="number", format="float", example=78.0),
     *                     @OA\Property(property="rating", type="number", format="float", example=4.4),
     *                     @OA\Property(property="review_count", type="integer", example=120),
     *                     @OA\Property(property="reviews", type="array", @OA\Items(type="object")),
     *                     @OA\Property(property="website", type="string", example="https://example.com"),
     *                     @OA\Property(property="maps_url", type="string"),
     *                     @OA\Property(property="social_links", type="array", @OA\Items(type="string")),
     *                     @OA\Property(
     *                         property="social_profiles",
     *                         type="array",
     *                         @OA\Items(
     *                             @OA\Property(property="platform", type="string", example="facebook"),
     *                             @OA\Property(property="url", type="string", example="https://facebook.com/example"),
     *                             @OA\Property(property="verified", type="boolean", example=true),
     *                             @OA\Property(property="source", type="string", example="website")
     *                         )
     *                     ),
     *                     @OA\Property(property="place_id", type="string"),
     *                     @OA\Property(property="name", type="string"),
     *                     @OA\Property(property="address", type="string"),
     *                     @OA\Property(property="source", type="string", example="google_places")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Missing required identification parameter",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="error_code", type="string", example="MISSING_IDENTIFICATION"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Business not found or could not be verified",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="error_code", type="string", example="BUSINESS_NOT_FOUND"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="error_code", type="string", example="VALIDATION_ERROR"),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=429,
     *         description="Too many requests - rate limit exceeded",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="error_code", type="string", example="RATE_LIMIT_EXCEEDED"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error during analysis",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="error_code", type="string", example="ANALYSIS_ERROR"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=503,
     *         description="Service unavailable - external service error",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="error_code", type="string", example="SERVICE_UNAVAILABLE"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     )
     * )
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function scan(Request $request): JsonResponse
    {
        try {
            // Validate incoming request
            $validated = $this->validateScanRequest($request);
            $auditUser = $this->resolveAuditUser($validated);
            $existingAudit = $this->resolveExistingAuditRun($validated, $auditUser);

            $placeId = $validated['place_id'] ?? null;
            $skipPlaces = (bool) ($validated['skip_places'] ?? false);

            if (!$skipPlaces && empty($placeId) && !empty($validated['business_name'])) {
                $candidatesResult = $this->placesService->searchCandidates(
                    $validated['business_name'],
                    $validated['location'] ?? null,
                    $validated['phone'] ?? null,
                    $validated['website'] ?? null
                );

                if (!$candidatesResult['success']) {
                    $auditRun = $this->createAuditRun(
                        $request,
                        $validated,
                        $auditUser,
                        'error',
                        null,
                        'PLACES_SEARCH_FAILED',
                        'Google Business Profile lookup failed',
                        $existingAudit
                    );

                    return $this->errorResponse(
                        'PLACES_SEARCH_FAILED',
                        'Google Business Profile lookup failed',
                        $candidatesResult['reason'] ?? null,
                        503,
                        $auditRun?->id
                    );
                }

                $candidates = $candidatesResult['candidates'] ?? [];

                if (!empty($candidates)) {
                    $selectionResponse = [
                        'status' => 'selection_required',
                        'message' => 'Select your business, or click Continue without Google Business Profile if your business is not listed.',
                        'candidates' => $candidates,
                        'total' => count($candidates),
                    ];

                    $auditRun = $this->createAuditRun(
                        $request,
                        $validated,
                        $auditUser,
                        'selection_required',
                        $selectionResponse,
                        null,
                        null,
                        $existingAudit
                    );

                    if ($auditRun) {
                        $selectionResponse['audit_id'] = $auditRun->id;
                    }

                    return response()->json([
                        ...$selectionResponse,
                    ], 200);
                }

                // No candidates found: continue scan without forcing a second request.
                $validated['skip_places'] = true;
            }

            // Queue async audit execution and return immediately.
            $reservation = $this->restrictionService->reserveAuditSlot($auditUser);
            if (!($reservation['allowed'] ?? false)) {
                return $this->errorResponse(
                    $reservation['code'] ?? 'PLAN_RESTRICTED',
                    $reservation['message'] ?? 'Your current plan does not allow starting this audit.',
                    $reservation['details'] ?? null,
                    403
                );
            }

            $auditRun = $this->createAuditRun(
                $request,
                $validated,
                $auditUser,
                'pending',
                null,
                null,
                null,
                $existingAudit
            );

            if (!$auditRun) {
                $this->restrictionService->releaseAuditSlot($auditUser);
                return $this->errorResponse(
                    'AUDIT_CREATE_FAILED',
                    'Unable to start your audit right now. Please try again.',
                    null,
                    500
                );
            }

            try {
                RunAuditScanJob::dispatch($auditRun->id);
            } catch (\Throwable $e) {
                $this->restrictionService->releaseAuditSlot($auditUser);

                return $this->errorResponse(
                    'AUDIT_QUEUE_FAILED',
                    'Unable to queue your audit right now. Please try again.',
                    null,
                    500
                );
            }

            return response()->json([
                'status' => 'queued',
                'message' => 'Your audit has started successfully. We will email you as soon as the analysis is complete.',
                'audit' => $this->serializeAuditRunSummary($auditRun),
                'audit_id' => $auditRun->id,
            ], 202);

        } catch (ValidationException $e) {
            return $this->errorResponse(
                'VALIDATION_ERROR',
                'Invalid request parameters',
                $e->errors(),
                422
            );
        } catch (\Exception $e) {
            \Log::error('Reputation scan error: ' . $e->getMessage(), [
                'exception' => $e,
                'request' => $request->all()
            ]);

            return $this->errorResponse(
                'ANALYSIS_ERROR',
                'An error occurred during analysis',
                null,
                500
            );
        }
    }

    /**
     * Fetch audit history for a user.
     */
    public function history(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'user_id' => 'nullable|integer|exists:users,id',
                'lookup_email' => 'nullable|string|email',
                'limit' => 'nullable|integer|min:1|max:200',
            ]);

            $user = $this->resolveAuditUser($validated);
            if (!$user) {
                return $this->errorResponse('USER_NOT_FOUND', 'User not found.', null, 404);
            }

            $limit = (int) ($validated['limit'] ?? 50);
            $audits = AuditRun::query()
                ->where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            return response()->json([
                'status' => 'success',
                'total' => $audits->count(),
                'audits' => $audits->map(fn (AuditRun $audit) => $this->serializeAuditRunSummary($audit))->values(),
            ]);
        } catch (ValidationException $e) {
            return $this->errorResponse(
                'VALIDATION_ERROR',
                'Invalid history request parameters.',
                $e->errors(),
                422
            );
        }
    }

    /**
     * Fetch one audit history record for a user.
     */
    public function historyItem(Request $request, int $audit): JsonResponse
    {
        try {
            $validated = $request->validate([
                'user_id' => 'nullable|integer|exists:users,id',
                'lookup_email' => 'nullable|string|email',
            ]);

            $user = $this->resolveAuditUser($validated);
            if (!$user) {
                return $this->errorResponse('USER_NOT_FOUND', 'User not found.', null, 404);
            }

            $auditRun = AuditRun::query()
                ->where('id', $audit)
                ->where('user_id', $user->id)
                ->first();

            if (!$auditRun) {
                return $this->errorResponse('AUDIT_NOT_FOUND', 'Audit record not found.', null, 404);
            }

            return response()->json([
                'status' => 'success',
                'audit' => $this->serializeAuditRunDetail($auditRun),
            ]);
        } catch (ValidationException $e) {
            return $this->errorResponse(
                'VALIDATION_ERROR',
                'Invalid history-item request parameters.',
                $e->errors(),
                422
            );
        }
    }

    /**
     * Validate scan request
     * 
     * @param Request $request
     * @return array
     * @throws ValidationException
     */
    private function validateScanRequest(Request $request): array
    {
        // Check if we have website OR (phone AND location)
        $hasWebsite = $request->filled('website');
        $hasPhone = $request->filled('phone');
        $hasLocation = $request->filled('location');
        $hasBusinessName = $request->filled('business_name');

        // Validation rules
        $rules = [
            'user_id' => 'nullable|integer|exists:users,id',
            'lookup_email' => 'nullable|string|email',
            'audit_id' => 'nullable|integer|exists:audit_runs,id',
            'business_name' => 'nullable|string|min:2|max:100',
            'website' => 'nullable|url',
            'phone' => 'nullable|string|regex:/^\+?[0-9\s\-\(\)]+$/',
            'location' => 'nullable|string|min:3|max:100',
            'country' => 'nullable|string|size:2',
            'industry' => 'nullable|string|max:50',
            'place_id' => 'nullable|string|max:200',
            'skip_places' => 'nullable|boolean',
            'selected_place_name' => 'nullable|string|max:255',
            'selected_place_address' => 'nullable|string|max:500',
            'selected_place_rating' => 'nullable|numeric|min:0|max:5',
            'selected_place_review_count' => 'nullable|integer|min:0',
        ];

        $validated = $request->validate($rules);

        // Business identification validation
        $validIdentification = $hasWebsite || ($hasPhone && $hasLocation) || $hasBusinessName;

        if (!$validIdentification) {
            throw ValidationException::withMessages([
                'business_identification' => 'Provide business name OR website OR (phone + location).'
            ]);
        }

        return $validated;
    }

    private function resolveAuditUser(array $validated): ?User
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

    private function resolveExistingAuditRun(array $validated, ?User $user): ?AuditRun
    {
        $auditId = $validated['audit_id'] ?? null;
        if (!$auditId) {
            return null;
        }

        if (!$user) {
            throw ValidationException::withMessages([
                'audit_id' => 'Unable to resume this audit. Sign in and try again.',
            ]);
        }

        $auditRun = AuditRun::query()
            ->where('id', $auditId)
            ->where('user_id', $user->id)
            ->first();

        if (!$auditRun) {
            throw ValidationException::withMessages([
                'audit_id' => 'Audit record not found for this user.',
            ]);
        }

        if ($auditRun->status !== 'selection_required') {
            throw ValidationException::withMessages([
                'audit_id' => 'Only audits that need selection can be resumed.',
            ]);
        }

        return $auditRun;
    }

    private function createAuditRun(
        Request $request,
        array $validated,
        ?User $user,
        string $status,
        ?array $responsePayload = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        ?AuditRun $existingAudit = null
    ): ?AuditRun {
        try {
            $scanDate = null;
            $reputationScore = null;

            if ($responsePayload && isset($responsePayload['scan_date'])) {
                try {
                    $scanDate = (string) $responsePayload['scan_date'];
                } catch (\Throwable $e) {
                    $scanDate = null;
                }
            }

            if ($responsePayload && isset($responsePayload['results']['reputation_score'])) {
                $reputationScore = (int) $responsePayload['results']['reputation_score'];
            }

            $attributes = [
                'user_id' => $existingAudit?->user_id ?? $user?->id,
                'status' => $status,
                'business_name' => $responsePayload['business_name']
                    ?? ($validated['business_name'] ?? null),
                'website' => $responsePayload['verified_website']
                    ?? ($validated['website'] ?? null),
                'phone' => $responsePayload['verified_phone']
                    ?? ($validated['phone'] ?? null),
                'location' => $responsePayload['verified_location']
                    ?? ($validated['location'] ?? null),
                'industry' => $validated['industry'] ?? null,
                'place_id' => $validated['place_id'] ?? null,
                'skip_places' => (bool) ($validated['skip_places'] ?? false),
                'reputation_score' => $reputationScore,
                'scan_date' => $scanDate,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'request_payload' => $validated,
                'response_payload' => $responsePayload,
            ];

            if ($existingAudit) {
                $existingAudit->fill($attributes);
                $existingAudit->save();
                return $existingAudit;
            }

            return AuditRun::query()->create($attributes);
        } catch (\Throwable $e) {
            \Log::warning('Failed to persist audit run.', [
                'exception' => $e,
            ]);

            return null;
        }
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

    /**
     * Return error response
     * 
     * @param string $code
     * @param string $message
     * @param mixed $details
     * @param int $httpCode
     * @return JsonResponse
     */
    private function errorResponse(
        string $code,
        string $message,
        $details = null,
        int $httpCode = 422,
        ?int $auditId = null
    ): JsonResponse {
        $response = [
            'status' => 'error',
            'code' => $code,
            'message' => $message
        ];

        if ($details !== null) {
            $response['details'] = $details;
        }

        if ($auditId !== null) {
            $response['audit_id'] = $auditId;
        }

        return response()->json($response, $httpCode);
    }
}
