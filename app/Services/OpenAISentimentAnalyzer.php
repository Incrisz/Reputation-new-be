<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class OpenAISentimentAnalyzer
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
     * Analyze sentiment and extract themes
     * 
     * @param array $mentions
     * @return array
     */
    public function analyze(array $mentions): array
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
                    $mention['content'] = app(SerperSearchService::class)->extractContent($mention['url']);
                }

                if (empty($mention['content'])) {
                    $mention['content'] = $mention['snippet'] ?? '';
                }

                // Analyze this mention
                $result = $this->analyzeMention($mention);

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
    private function analyzeMention(array $mention): array
    {
        try {
            $content = $mention['content'] ?? '';
            $source = $mention['source'] ?? 'unknown';
            $url = $mention['url'] ?? '';
            $title = $mention['title'] ?? '';
            $snippet = $mention['snippet'] ?? '';

            $prompt = $this->buildAnalysisPrompt($content, $source, $url, $title, $snippet);
            
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
        string $content,
        string $source,
        string $url,
        string $title,
        string $snippet
    ): string
    {
        return <<<PROMPT
You are a reputation analysis engine.

You are analyzing ONE online mention of a business.

Your job is to:
1. Determine sentiment (positive, negative, or neutral)
2. Extract concrete themes if sentiment is not neutral
3. Justify sentiment based on visible signals (ratings, language, context)

IMPORTANT RULES:
- Do NOT default to neutral unless there is truly no opinion.
- Review pages (Trustpilot, Yelp, BBB, Google Reviews, Glassdoor, Indeed) almost always express sentiment.
- Numeric ratings MUST influence sentiment:
  - Rating >= 4.0 -> positive
  - Rating between 2.5 and 3.9 -> mixed / neutral
  - Rating < 2.5 -> negative
- Words like "complaint", "bad", "terrible", "poor", "scam", "worst" -> negative
- Words like "great", "excellent", "amazing", "love", "best" -> positive
- Official company pages without opinions -> neutral

SOURCE CONTEXT:
- Source type: {$source}
- URL: {$url}
- Page title: {$title}
- Search snippet (if available): {$snippet}

CONTENT:
{$content}

GUIDELINES:
- If the page is a review site, infer sentiment from the page purpose even if full reviews are not visible.
- If multiple opinions are implied, choose the dominant sentiment.
- If sentiment is neutral, themes MUST be an empty array.
- Do not explain your reasoning.
- Do not return text outside JSON.

Provide your response in this exact JSON format:
{
  "sentiment": "positive" | "negative" | "neutral",
  "themes": ["theme1", "theme2"],
  "justification": "Short signal-based justification"
}

Only output the JSON, no other text.
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
