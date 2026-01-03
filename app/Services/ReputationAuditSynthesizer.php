<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ReputationAuditSynthesizer
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
     * Synthesize a full reputation audit from scan outputs.
     *
     * @param array $analysis
     * @param array $mentions
     * @param array $scoreResult
     * @param array $recommendations
     * @param string $businessName
     * @param string|null $industry
     * @return array
     */
    public function synthesize(
        array $analysis,
        array $mentions,
        array $scoreResult,
        array $recommendations,
        string $businessName,
        ?string $industry = null
    ): array {
        try {
            $topMentions = $this->buildTopMentions(
                $analysis['top_mentions'] ?? [],
                $mentions
            );

            $payload = [
                'business' => [
                    'name' => $businessName,
                    'industry' => $industry,
                ],
                'engine_score' => $scoreResult['reputation_score'] ?? null,
                'sentiment_breakdown' => $analysis['sentiment_breakdown'] ?? [],
                'themes' => $analysis['themes'] ?? [],
                'top_mentions' => $topMentions,
                'recommendations' => $recommendations,
            ];

            $prompt = $this->buildAuditPrompt($payload);
            $response = $this->callLLM($prompt);

            if (!$response['success']) {
                return [
                    'success' => false,
                ];
            }

            $audit = $this->parseAuditResponse($response['content']);

            if (!$audit) {
                return [
                    'success' => false,
                ];
            }

            return [
                'success' => true,
                'audit' => $audit,
            ];
        } catch (\Exception $e) {
            \Log::error('Audit synthesis error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return [
                'success' => false,
            ];
        }
    }

    /**
     * Build top mention summaries with source hints.
     *
     * @param array $topMentions
     * @param array $mentions
     * @return array
     */
    private function buildTopMentions(array $topMentions, array $mentions): array
    {
        $mentionIndex = [];
        foreach ($mentions as $mention) {
            $url = $mention['url'] ?? '';
            if ($url === '') {
                continue;
            }

            $mentionIndex[$url] = $mention;
        }

        $summaries = [];
        foreach ($topMentions as $mention) {
            $url = $mention['url'] ?? '';
            $fallback = $mentionIndex[$url] ?? [];

            $summaries[] = [
                'url' => $url,
                'title' => $mention['title'] ?? ($fallback['title'] ?? ''),
                'source' => $mention['source'] ?? ($fallback['source'] ?? ''),
                'source_type' => $this->classifySourceType($url),
                'sentiment' => $mention['sentiment'] ?? '',
                'snippet' => $fallback['snippet'] ?? '',
            ];
        }

        return $summaries;
    }

    /**
     * Classify source type based on URL.
     *
     * @param string $url
     * @return string
     */
    private function classifySourceType(string $url): string
    {
        $url = strtolower($url);

        if (preg_match('/trustpilot\.com|yelp\.com|bbb\.org|consumeraffairs\.com|google\.com\/maps/', $url)) {
            return 'customer_reviews';
        }

        if (preg_match('/glassdoor\.com|indeed\.com|comparably\.com/', $url)) {
            return 'employee_reviews';
        }

        if (preg_match('/reddit\.com|quora\.com|stackexchange\.com|stackoverflow\.com/', $url)) {
            return 'forum';
        }

        if (preg_match('/twitter\.com|x\.com|facebook\.com|linkedin\.com|instagram\.com|tiktok\.com|threads\.net|youtube\.com/', $url)) {
            return 'social';
        }

        if (preg_match('/news\.google\.com|reuters\.com|apnews\.com|bbc\.com|cnn\.com|nytimes\.com|wsj\.com|forbes\.com|prnewswire\.com|businesswire\.com/', $url)) {
            return 'news';
        }

        return 'other';
    }

    /**
     * Build audit prompt with strict JSON output schema.
     *
     * @param array $payload
     * @return string
     */
    private function buildAuditPrompt(array $payload): string
    {
        $payloadJson = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
System / Instruction Prompt

You are an objective, enterprise-grade Reputation Analysis Engine.

Your task is to analyze public online signals about a business and produce a balanced, evidence-based reputation audit.

You MUST avoid optimism bias and pessimism bias.
You MUST reflect real-world perception accurately.

You must distinguish clearly between:

customer sentiment

employee sentiment

neutral / informational sources

Do NOT hallucinate marketing themes (e.g. "innovation", "creativity") unless they appear explicitly and repeatedly in source content.

1. Sentiment Analysis Rules

Apply source-aware weighting when determining sentiment:

Customer review platforms (Trustpilot, Yelp, BBB, ConsumerAffairs)
-> High confidence, high impact

Forums / discussions (Reddit, Quora)
-> Medium confidence, mixed sentiment unless clearly one-sided

Employee review platforms (Glassdoor, Indeed, Comparably)
-> Internal reputation only; do NOT let this dominate customer reputation

Official / social pages (LinkedIn, company website)
-> Mostly neutral unless strong sentiment is explicit

Neutral sources must not be over-penalized or over-rewarded.

Negative sentiment must never exceed 60% unless multiple independent customer review platforms strongly agree.

2. Reputation Score Rules

Output a reputation_score from 0-100

Scores below 30 indicate severe reputational crisis

Scores above 85 indicate exceptional reputation

Established global brands should typically fall between 40-75

The score must be consistent with the sentiment breakdown.

3. Theme Extraction Rules

Extract only themes that appear repeatedly or meaningfully

Merge overlapping themes into broader categories when appropriate
(e.g. "customer complaints", "product dissatisfaction", "UX issues" -> Customer Experience)

Separate:

Customer-facing themes

Employee-facing themes

Each theme must include:

theme name

frequency

sentiment (positive / negative)

4. Source Attribution Rules

For each top mention:

Assign sentiment conservatively

Review platforms default to mixed or negative, not neutral

Forums default to mixed unless clearly extreme

Do NOT mark complaint-heavy sites as neutral

5. Recommendation Rules

Recommendations must be:

Directly linked to the detected issues

Actionable and operational (not generic advice)

Suitable for executive or operations teams

Avoid vague statements like:

"Improve innovation"
"Focus more on creativity"

OUTPUT FORMAT (STRICT - JSON ONLY):

{
  "reputation_score": 0,
  "sentiment_breakdown": {
    "customer": { "positive": 0, "negative": 0, "neutral": 0 },
    "employee": { "positive": 0, "negative": 0, "neutral": 0 },
    "neutral_sources": 0
  },
  "customer_themes": [
    { "theme": "Customer Experience", "frequency": 0, "sentiment": "negative" }
  ],
  "employee_themes": [
    { "theme": "Work Culture", "frequency": 0, "sentiment": "positive" }
  ],
  "top_mentions": [
    {
      "url": "",
      "source_type": "",
      "sentiment": "positive | negative | neutral",
      "summary": ""
    }
  ],
  "recommendations": ["..."]
}

INPUT DATA (JSON):
{$payloadJson}

Return ONLY the JSON object. Do not include any extra text.
PROMPT;
    }

    /**
     * Call LLM API.
     *
     * @param string $prompt
     * @return array
     */
    private function callLLM(string $prompt): array
    {
        try {
            $headers = [
                'Content-Type' => 'application/json',
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
                            'content' => 'You are an objective reputation audit engine. Return JSON only.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                    'temperature' => 0.2,
                    'max_tokens' => 800,
                ]
            );

            if ($response->failed()) {
                \Log::error('Audit LLM API error: ' . $response->status() . ' ' . $response->body());
                return [
                    'success' => false,
                ];
            }

            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? '';

            return [
                'success' => true,
                'content' => $content,
            ];
        } catch (\Exception $e) {
            \Log::error('Audit LLM call error: ' . $e->getMessage());

            return [
                'success' => false,
            ];
        }
    }

    /**
     * Parse LLM response JSON.
     *
     * @param string $content
     * @return array|null
     */
    private function parseAuditResponse(string $content): ?array
    {
        $json = json_decode($content, true);

        if (!is_array($json)) {
            return null;
        }

        return $json;
    }
}
