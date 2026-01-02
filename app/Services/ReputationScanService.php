<?php

namespace App\Services;

class ReputationScanService
{
    private SerperSearchService $searchService;
    private OpenAISentimentAnalyzer $sentimentAnalyzer;
    private ReputationScoringEngine $scoringEngine;
    private RecommendationGenerator $recommendationGenerator;

    public function __construct(
        SerperSearchService $searchService,
        OpenAISentimentAnalyzer $sentimentAnalyzer,
        ReputationScoringEngine $scoringEngine,
        RecommendationGenerator $recommendationGenerator
    ) {
        $this->searchService = $searchService;
        $this->sentimentAnalyzer = $sentimentAnalyzer;
        $this->scoringEngine = $scoringEngine;
        $this->recommendationGenerator = $recommendationGenerator;
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
                    'recommendations' => $recommendations
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
