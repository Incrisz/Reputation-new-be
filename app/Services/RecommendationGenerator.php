<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class RecommendationGenerator
{
    private string $provider;
    private string $apiKey;
    private string $model;
    private string $baseUrl;

    public function __construct()
    {
        $this->provider = config('services.llm.provider');
        
        if ($this->provider === 'openrouter') {
            $this->apiKey = config('services.openrouter.api_key');
            $this->model = config('services.openrouter.model');
            $this->baseUrl = rtrim((string) config('services.openrouter.base_url'), '/');
        } else {
            $this->apiKey = config('services.openai.api_key');
            $this->model = config('services.openai.model');
            $this->baseUrl = rtrim((string) config('services.openai.base_url'), '/');
        }
    }

    /**
     * Generate recommendations based on sentiment analysis
     * 
     * @param array $sentimentAnalysis
     * @param string $businessName
     * @param string|null $industry
     * @return array
     */
    public function generate(array $sentimentAnalysis, string $businessName, ?string $industry = null): array
    {
        try {
            $prompt = $this->buildRecommendationPrompt($sentimentAnalysis, $businessName, $industry);
            
            $response = $this->callLLM($prompt);

            if (!$response['success']) {
                return $this->getDefaultRecommendations($sentimentAnalysis);
            }

            $recommendations = $this->parseRecommendations($response['content']);

            return $recommendations ?? $this->getDefaultRecommendations($sentimentAnalysis);

        } catch (\Exception $e) {
            \Log::warning('Recommendation generation error: ' . $e->getMessage());
            return $this->getDefaultRecommendations($sentimentAnalysis);
        }
    }

    /**
     * Build recommendation prompt
     * 
     * @param array $sentimentAnalysis
     * @param string $businessName
     * @param string|null $industry
     * @return string
     */
    private function buildRecommendationPrompt(array $sentimentAnalysis, string $businessName, ?string $industry = null): string
    {
        $sentimentBreakdown = $sentimentAnalysis['sentiment_breakdown'] ?? [];
        $topThemes = $sentimentAnalysis['themes'] ?? [];

        $themesText = '';
        foreach ($topThemes as $theme) {
            $sentiment = $theme['sentiment'] ?? 'neutral';
            $frequency = $theme['frequency'] ?? 0;
            $themesText .= "- {$theme['theme']} ({$sentiment}, mentioned {$frequency} times)\n";
        }

        $industryText = $industry ? "Industry: {$industry}\n" : '';

        return <<<PROMPT
You are a business reputation consultant. Based on the following sentiment analysis, provide 3-5 specific, actionable recommendations to improve online reputation.

Business: {$businessName}
{$industryText}

Sentiment Breakdown:
- Positive mentions: {$sentimentBreakdown['positive']}%
- Negative mentions: {$sentimentBreakdown['negative']}%
- Neutral mentions: {$sentimentBreakdown['neutral']}%

Top Topics Discussed:
{$themesText}

Provide recommendations in this exact JSON format:
{
    "recommendations": [
        "Recommendation 1",
        "Recommendation 2",
        "Recommendation 3"
    ]
}

Make recommendations specific, actionable, and directly addressing the negative themes if any. Only output the JSON, no other text.
PROMPT;
    }

    /**
     * Call LLM API
     * 
     * @param string $prompt
     * @return array
     */
    private function callLLM(string $prompt): array
    {
        try {
            $headers = [
                'Content-Type' => 'application/json'
            ];

            if ($this->provider === 'openrouter') {
                $headers['Authorization'] = 'Bearer ' . $this->apiKey;
                $headers['HTTP-Referer'] = config('services.openrouter.site_url');
                $headers['X-Title'] = config('services.openrouter.app_title');
            } else {
                $headers['Authorization'] = 'Bearer ' . $this->apiKey;
            }

            $response = Http::withHeaders($headers)->post(
                $this->baseUrl . '/chat/completions',
                [
                    'model' => $this->model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a business reputation consultant. Provide clear, actionable recommendations. Always respond with valid JSON only.'
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt
                        ]
                    ],
                    'temperature' => 0.5,
                    'max_tokens' => 500
                ]
            );

            if ($response->failed()) {
                \Log::error('LLM API error: ' . $response->status() . ' ' . $response->body());
                return [
                    'success' => false
                ];
            }

            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? '';

            return [
                'success' => true,
                'content' => $content
            ];

        } catch (\Exception $e) {
            \Log::error('LLM call error: ' . $e->getMessage());
            return [
                'success' => false
            ];
        }
    }

    /**
     * Parse recommendations from LLM response
     * 
     * @param string $content
     * @return array|null
     */
    private function parseRecommendations(string $content): ?array
    {
        try {
            $json = json_decode($content, true);

            if (!$json || !isset($json['recommendations'])) {
                return null;
            }

            return $json['recommendations'];

        } catch (\Exception $e) {
            \Log::warning('Failed to parse recommendations: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get default recommendations based on sentiment
     * 
     * @param array $sentimentAnalysis
     * @return array
     */
    private function getDefaultRecommendations(array $sentimentAnalysis): array
    {
        $breakdown = $sentimentAnalysis['sentiment_breakdown'] ?? [];
        $themes = $sentimentAnalysis['themes'] ?? [];
        $recommendations = [];

        // Find top negative themes
        $negativeThemes = array_filter($themes, fn($t) => ($t['sentiment'] ?? 'neutral') === 'negative');
        
        if (!empty($negativeThemes)) {
            $topNegative = array_shift($negativeThemes);
            $recommendations[] = "Address concerns about: " . $topNegative['theme'];
        }

        if (($breakdown['negative'] ?? 0) > 30) {
            $recommendations[] = "Respond professionally to negative reviews and address common complaints";
        }

        if (($breakdown['positive'] ?? 0) > 60) {
            $recommendations[] = "Leverage positive sentiment in marketing campaigns and ask satisfied customers for testimonials";
        } else {
            $recommendations[] = "Focus on improving service quality and customer experience";
        }

        $recommendations[] = "Monitor online mentions regularly and respond promptly to feedback";

        return array_slice($recommendations, 0, 5);
    }
}
