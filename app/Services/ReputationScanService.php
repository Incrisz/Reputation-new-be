<?php

namespace App\Services;

class ReputationScanService
{
    private SearchServiceContract $searchService;
    private OpenAISentimentAnalyzer $sentimentAnalyzer;
    private ReputationScoringEngine $scoringEngine;
    private RecommendationGenerator $recommendationGenerator;
    private ReputationAuditSynthesizer $auditSynthesizer;
    private GooglePlacesService $placesService;
    private OnlineProfileScoringEngine $onlineProfileScoringEngine;

    public function __construct(
        SearchServiceContract $searchService,
        OpenAISentimentAnalyzer $sentimentAnalyzer,
        ReputationScoringEngine $scoringEngine,
        RecommendationGenerator $recommendationGenerator,
        ReputationAuditSynthesizer $auditSynthesizer,
        GooglePlacesService $placesService,
        OnlineProfileScoringEngine $onlineProfileScoringEngine
    ) {
        $this->searchService = $searchService;
        $this->sentimentAnalyzer = $sentimentAnalyzer;
        $this->scoringEngine = $scoringEngine;
        $this->recommendationGenerator = $recommendationGenerator;
        $this->auditSynthesizer = $auditSynthesizer;
        $this->placesService = $placesService;
        $this->onlineProfileScoringEngine = $onlineProfileScoringEngine;
    }

    /**
     * Execute complete reputation scan
     * 
     * @param array $businessData
     * @return array
     */
    public function scan(array $businessData): array
    {
        try {
            $businessName = $businessData['business_name'] ?? '';
            $location = $businessData['verified_location'] ?? null;
            $industry = $businessData['industry'] ?? null;
            $phone = $businessData['verified_phone'] ?? null;
            $website = $businessData['verified_website'] ?? null;
            $placeId = $businessData['place_id'] ?? null;
            $skipPlaces = (bool) ($businessData['skip_places'] ?? false);
            $country = $businessData['country'] ?? null;

            if ($skipPlaces) {
                $placesProfile = [
                    'success' => false,
                    'found' => false,
                    'reason' => 'skipped'
                ];
            } else {
                $placesProfile = $this->placesService->fetchProfile(
                    $businessName,
                    $location,
                    $phone,
                    $website,
                    $placeId
                );
            }

            $visibilityScore = $this->onlineProfileScoringEngine->calculate(
                $placesProfile['rating'] ?? null,
                $placesProfile['review_count'] ?? null
            );
            $socialProfiles = $placesProfile['social_profiles'] ?? [];
            if (empty($socialProfiles) && !empty($website)) {
                $socialProfiles = $this->placesService->extractSocialProfilesFromWebsite($website);
            }
            $platforms = ['facebook', 'instagram', 'linkedin', 'tiktok', 'x', 'youtube', 'threads'];
            $existingPlatforms = [];
            foreach ($socialProfiles as $profile) {
                $platformKey = $profile['platform'] ?? null;
                if ($platformKey) {
                    $existingPlatforms[$platformKey] = true;
                }
            }

            $missingPlatforms = array_values(array_diff($platforms, array_keys($existingPlatforms)));
            if (!empty($missingPlatforms) && $businessName !== '') {
                $fallbackProfiles = $this->searchService->searchSocialProfiles(
                    $businessName,
                    $missingPlatforms,
                    $country,
                    $location
                );

                foreach ($fallbackProfiles as $profile) {
                    $platformKey = $profile['platform'] ?? null;
                    if ($platformKey && !isset($existingPlatforms[$platformKey])) {
                        $socialProfiles[] = $profile;
                        $existingPlatforms[$platformKey] = true;
                    }
                }
            }

            $socialLinks = array_values(array_unique(array_map(function ($profile) {
                return $profile['url'];
            }, $socialProfiles)));

            // Step 2: Search for mentions
            $searchResult = $this->searchService->search($businessName, $location);

            if (!$searchResult['success'] || empty($searchResult['mentions'])) {
                return [
                    'success' => false,
                    'error_code' => 'BUSINESS_NOT_FOUND',
                    'message' => 'No mentions found for this business',
                    'details' => 'Try verifying with different business information',
                    'http_code' => 404
                ];
            }

            // Step 3: Analyze sentiment
            $analysis = $this->sentimentAnalyzer->analyze(
                $searchResult['mentions'],
                $businessName,
                $industry
            );

            // Calculate reputation score
            $scoreResult = $this->scoringEngine->calculateScore(
                $searchResult['mentions'],
                $analysis
            );

            // Generate recommendations
            $recommendations = $this->recommendationGenerator->generate(
                $analysis,
                $businessName,
                $industry
            );

            // Synthesize full reputation audit
            $auditResult = $this->auditSynthesizer->synthesize(
                $analysis,
                $searchResult['mentions'],
                $scoreResult,
                $recommendations,
                $businessName,
                $industry
            );

            // Compile final result
            return [
                'success' => true,
                'business_name' => $businessName,
                'verified_website' => $businessData['verified_website'] ?? null,
                'verified_location' => $businessData['verified_location'] ?? null,
                'verified_phone' => $businessData['verified_phone'] ?? null,
                'scan_date' => now()->toIso8601String(),
                'results' => [
                    'reputation_score' => $scoreResult['reputation_score'],
                    'sentiment_breakdown' => $analysis['sentiment_breakdown'],
                    'top_themes' => $analysis['themes'],
                    'top_mentions' => $analysis['top_mentions'],
                    'recommendations' => $recommendations,
                    'audit' => $auditResult['success'] ? $auditResult['audit'] : null,
                    'online_profile' => [
                        'found' => $placesProfile['found'] ?? false,
                        'visibility_score' => $visibilityScore['visibility_score'] ?? null,
                        'rating' => $placesProfile['rating'] ?? null,
                        'review_count' => $placesProfile['review_count'] ?? null,
                        'reviews' => $placesProfile['reviews'] ?? [],
                        'website' => $placesProfile['website'] ?? null,
                        'maps_url' => $placesProfile['maps_url'] ?? null,
                        'social_links' => $socialLinks,
                        'social_profiles' => $socialProfiles,
                        'place_id' => $placesProfile['place_id'] ?? null,
                        'name' => $placesProfile['name'] ?? null,
                        'address' => $placesProfile['address'] ?? null,
                        'source' => $placesProfile['source'] ?? null
                    ]
                ]
            ];

        } catch (\Exception $e) {
            \Log::error('Reputation scan error: ' . $e->getMessage(), [
                'exception' => $e,
                'business_data' => $businessData
            ]);

            return [
                'success' => false,
                'error_code' => 'ANALYSIS_ERROR',
                'message' => 'An error occurred during scan',
                'details' => null,
                'http_code' => 500
            ];
        }
    }
}
