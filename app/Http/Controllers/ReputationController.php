<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use App\Services\BusinessVerificationService;
use App\Services\ReputationScanService;
use App\Services\GooglePlacesService;

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
    private BusinessVerificationService $verificationService;
    private ReputationScanService $scanService;
    private GooglePlacesService $placesService;

    public function __construct(
        BusinessVerificationService $verificationService,
        ReputationScanService $scanService,
        GooglePlacesService $placesService
    ) {
        $this->verificationService = $verificationService;
        $this->scanService = $scanService;
        $this->placesService = $placesService;
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
                    return $this->errorResponse(
                        'PLACES_SEARCH_FAILED',
                        'Google Places search failed',
                        $candidatesResult['reason'] ?? null,
                        503
                    );
                }

                $candidates = $candidatesResult['candidates'] ?? [];

                if (!empty($candidates)) {
                    return response()->json([
                        'status' => 'selection_required',
                        'message' => 'Select a business or set skip_places=true to continue without Google Places.',
                        'candidates' => $candidates,
                        'total' => count($candidates)
                    ], 200);
                }

                // No candidates found: continue scan without forcing a second request.
                $validated['skip_places'] = true;
            }

            // Step 1: Verify business (website OR phone+location)
            $verification = $this->verificationService->verify($validated);

            if (!$verification['success']) {
                return $this->errorResponse(
                    $verification['error_code'],
                    $verification['message'],
                    $verification['details'] ?? null,
                    $verification['http_code'] ?? 422
                );
            }

            // Step 2 & 3: Scan web and analyze reputation
            $scanResult = $this->scanService->scan($verification['business_data']);

            if (!$scanResult['success']) {
                return $this->errorResponse(
                    $scanResult['error_code'],
                    $scanResult['message'],
                    null,
                    $scanResult['http_code'] ?? 500
                );
            }

            // Return success response
            return response()->json([
                'status' => 'success',
                'business_name' => $scanResult['business_name'],
                'verified_website' => $scanResult['verified_website'] ?? null,
                'verified_location' => $scanResult['verified_location'] ?? null,
                'verified_phone' => $scanResult['verified_phone'] ?? null,
                'scan_date' => $scanResult['scan_date'],
                'results' => $scanResult['results']
            ], 200);

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
            'business_name' => 'nullable|string|min:2|max:100',
            'website' => 'nullable|url',
            'phone' => 'nullable|string|regex:/^\+?[0-9\s\-\(\)]+$/',
            'location' => 'nullable|string|min:3|max:100',
            'country' => 'nullable|string|size:2',
            'industry' => 'nullable|string|max:50',
            'place_id' => 'nullable|string|max:200',
            'skip_places' => 'nullable|boolean'
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
        int $httpCode = 422
    ): JsonResponse {
        $response = [
            'status' => 'error',
            'code' => $code,
            'message' => $message
        ];

        if ($details !== null) {
            $response['details'] = $details;
        }

        return response()->json($response, $httpCode);
    }
}
