<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class OpenAISentimentAnalyzer
{
    private SearchServiceContract $searchService;
    private string $provider;
    private string $apiKey;
    private string $model;
    private string $baseUrl;

    public function __construct(SearchServiceContract $searchService)
    {
        $this->searchService = $searchService;
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
     * Analyze sentiment and extract themes
     * 
     * @param array $mentions
     * @return array
     */
    public function analyze(array $mentions, string $businessName = '', ?string $industry = null): array
    {
        $analysis = [
            'sentiment_breakdown' => [
                'positive' => 0,
                'negative' => 0,
                'neutral' => 0
            ],
            'themes' => [],
            'top_mentions' => [],
            'total_mentions' => 0
        ];

        if (empty($mentions)) {
            return $analysis;
        }

        $analysis['total_mentions'] = count($mentions);
        $themeCounts = [];
        $sentimentCounts = [
            'positive' => 0,
            'negative' => 0,
            'neutral' => 0
        ];

        foreach ($mentions as $mention) {
            try {
                // Extract content if not already done
                if (empty($mention['content'])) {
                    $mention['content'] = $this->searchService->extractContent($mention['url']);
                }

                if (empty($mention['content'])) {
                    $mention['content'] = $mention['snippet'] ?? '';
                }

                // Analyze this mention
                $result = $this->analyzeMention($mention, $businessName, $industry);

                if ($result['success']) {
                    // Track sentiment
                    $sentiment = $result['sentiment'];
                    $sentimentCounts[$sentiment]++;

                    // Track themes
                    if (!empty($result['themes'])) {
                        foreach ($result['themes'] as $theme) {
                            if (!isset($themeCounts[$theme])) {
                                $themeCounts[$theme] = [
                                    'count' => 0,
                                    'sentiment' => $sentiment
                                ];
                            }
                            $themeCounts[$theme]['count']++;
                        }
                    }

                    // Track top mentions
                    $analysis['top_mentions'][] = [
                        'url' => $mention['url'],
                        'title' => $mention['title'],
                        'sentiment' => $sentiment,
                        'source' => $mention['source']
                    ];
                }

            } catch (\Exception $e) {
                \Log::warning('Failed to analyze mention ' . $mention['url'] . ': ' . $e->getMessage());
                continue;
            }
        }

        // Calculate percentages
        $total = array_sum($sentimentCounts);
        if ($total > 0) {
            $analysis['sentiment_breakdown'] = [
                'positive' => (int)round(($sentimentCounts['positive'] / $total) * 100),
                'negative' => (int)round(($sentimentCounts['negative'] / $total) * 100),
                'neutral' => (int)round(($sentimentCounts['neutral'] / $total) * 100)
            ];
        }

        // Sort themes by frequency
        uasort($themeCounts, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        // Build themes array (top 10)
        $analysis['themes'] = array_slice(
            array_map(function ($theme, $data) {
                return [
                    'theme' => $theme,
                    'frequency' => $data['count'],
                    'sentiment' => $data['sentiment']
                ];
            }, array_keys($themeCounts), array_values($themeCounts)),
            0,
            10
        );

        // Limit top mentions to 10
        $analysis['top_mentions'] = array_slice($analysis['top_mentions'], 0, 10);

        return $analysis;
    }

    /**
     * Analyze single mention
     * 
     * @param string $content
     * @param string $source
     * @param float $sourceWeight
     * @return array
     */
    private function analyzeMention(array $mention, string $businessName, ?string $industry): array
    {
        try {
            $content = $mention['content'] ?? '';
            $source = $mention['source'] ?? 'unknown';
            $url = $mention['url'] ?? '';
            $title = $mention['title'] ?? '';
            $snippet = $mention['snippet'] ?? '';
            $snippetOrText = $content !== '' ? $content : $snippet;
            $businessName = trim($businessName) !== '' ? $businessName : 'Unknown';
            $industryText = $industry ?? 'Unknown';

            $prompt = $this->buildAnalysisPrompt(
                $businessName,
                $industryText,
                $source,
                $url,
                $title,
                $snippetOrText
            );
            
            $response = $this->callLLM($prompt);

            if (!$response['success']) {
                return [
                    'success' => false
                ];
            }

            \Log::info('Sentiment analysis LLM response', [
                'url' => $url,
                'response' => $response['content'] ?? ''
            ]);

            $result = $this->parseAnalysisResponse($response['content']);

            return array_merge($result, ['success' => true]);

        } catch (\Exception $e) {
            \Log::warning('Sentiment analysis error: ' . $e->getMessage());

            return [
                'success' => false
            ];
        }
    }

    /**
     * Build analysis prompt for LLM
     * 
     * @param string $content
     * @param string $source
     * @return string
     */
    private function buildAnalysisPrompt(
        string $businessName,
        string $industry,
        string $source,
        string $url,
        string $title,
        string $snippetOrText
    ): string
    {
        $socialActive = (bool) config('services.social.active');
        $socialRules = $socialActive
            ? <<<RULES
7. SOCIAL MEDIA RULES (VERY IMPORTANT)
- SOCIAL_OFFICIAL pages (company profiles, brand pages):
  -> Always NEUTRAL unless explicit praise or criticism exists.
- SOCIAL_POST pages (individual public posts, threads, comments):
  -> Express direct personal opinion.
  -> Complaints, criticism, sarcasm -> NEGATIVE
  -> Praise, recommendations, excitement -> POSITIVE
  -> Announcements without opinion -> NEUTRAL
- Emojis, strong language, slang, or emotional tone MUST influence sentiment.
RULES
            : <<<RULES
7. SOCIAL MEDIA POSTS
- Public social media posts express direct sentiment.
- Complaints, rants, or viral criticism -> NEGATIVE
- Praise, recommendations, or celebration -> POSITIVE
- Announcements without opinion -> NEUTRAL
RULES;

        return <<<PROMPT
You are a reputation sentiment classification engine.

You MUST return a valid JSON object.
You MUST NOT return an empty response.
You MUST NOT explain your reasoning.
You MUST return ONLY JSON.

You are analyzing ONE online mention of a business.

BUSINESS:
- Name: {$businessName}
- Industry: {$industry}

MENTION CONTEXT:
- Source type: {$source}
- URL: {$url}
- Page title: {$title}
- Snippet or extracted text:
{$snippetOrText}

CRITICAL SENTIMENT RULES (READ CAREFULLY):

1. REVIEW AGGREGATOR BIAS (VERY IMPORTANT)
- Pages from Trustpilot, Yelp, BBB, ConsumerAffairs, Google Reviews, or similar
  are NOT neutral by default.
- If the page is a review aggregation page AND no clear positive language exists,
  sentiment should be NEGATIVE by default.
- Neutral is allowed ONLY if the page clearly shows mixed or balanced sentiment.

2. EMPLOYEE REVIEW BIAS
- Glassdoor, Indeed, Comparably pages are POSITIVE by default
  unless strong negative language exists.

3. COMPLAINT / DISCUSSION PAGES
- Forums, Reddit threads, complaint pages, and discussion boards
  default to NEGATIVE unless clearly praising the business.

4. OFFICIAL BRAND PAGES
- Official company pages (LinkedIn, Apple.com, Feedback pages)
  are NEUTRAL unless explicit praise or criticism is visible.

5. KEYWORD OVERRIDES
- Words like "complaint", "poor", "bad", "worst", "scam", "terrible" -> NEGATIVE
- Words like "great", "excellent", "amazing", "best", "love" -> POSITIVE

6. NEWS ARTICLES
- If the source is a news article:
  - Legal action, fines, recalls, scandals -> NEGATIVE
  - Awards, rankings, innovations -> POSITIVE
  - Neutral reporting -> NEUTRAL
- News articles carry higher reputational impact than reviews.

{$socialRules}

THEMES:
- If sentiment is POSITIVE or NEGATIVE, extract 1-3 short themes.
- Themes must be concrete (e.g. "customer service", "pricing", "work culture").
- If sentiment is NEUTRAL, themes MUST be an empty array.

OUTPUT FORMAT (STRICT):

{
  "sentiment": "positive | negative | neutral",
  "confidence": 0.0,
  "themes": []
}

FAILSAFE RULES:
- If information is limited, infer sentiment from the TYPE OF PAGE.
- When in doubt between neutral and negative for review pages, choose NEGATIVE.
- NEVER return an empty response.
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
                            'content' => 'You are a sentiment analysis expert. Analyze business mentions and extract sentiment and themes. Always respond with valid JSON only.'
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt
                        ]
                    ],
                    'temperature' => 0.3,
                    'max_tokens' => 300
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
     * Parse LLM response
     * 
     * @param string $content
     * @return array
     */
    private function parseAnalysisResponse(string $content): array
    {
        try {
            // Extract JSON from response
            $json = json_decode($content, true);

            if (!$json) {
                return [
                    'sentiment' => 'neutral',
                    'themes' => []
                ];
            }

            $sentiment = $json['sentiment'] ?? 'neutral';
            $themes = $json['themes'] ?? [];

            if ($sentiment === 'neutral') {
                $themes = [];
            }

            return [
                'sentiment' => $sentiment,
                'confidence' => $json['confidence'] ?? 0.5,
                'themes' => $themes,
                'summary' => $json['summary'] ?? ($json['justification'] ?? '')
            ];

        } catch (\Exception $e) {
            \Log::warning('Failed to parse analysis response: ' . $e->getMessage());

            return [
                'sentiment' => 'neutral',
                'themes' => []
            ];
        }
    }
}
