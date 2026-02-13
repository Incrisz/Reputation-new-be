<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class LLMWebSearchService implements SearchServiceContract
{
    private string $provider;
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    private bool $fallbackToSerper;
    private array $socialPlatforms = [
        'facebook',
        'instagram',
        'linkedin',
        'tiktok',
        'x',
        'youtube',
        'threads'
    ];

    public function __construct()
    {
        $this->provider = config('services.llm.provider');
        $this->fallbackToSerper = (bool) config('services.search.llm_fallback_to_serper', true);
        
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
     * Search for business mentions using LLM with web_search tool
     * 
     * @param string $businessName
     * @param string|null $location
     * @return array
     */
    public function search(string $businessName, ?string $location = null): array
    {
        try {
            $mentions = [];
            $queries = $this->generateSearchQueries($businessName, $location);

            foreach ($queries as $query) {
                $results = $this->executeWebSearch($query);
                if ($results['success']) {
                    $mentions = array_merge($mentions, $results['mentions']);
                }
            }

            // Remove duplicates and limit to high-impact mentions
            $mentions = $this->filterHighSignalMentions($mentions);

            if (empty($mentions)) {
                $fallback = $this->fallbackSearch($businessName, $location);
                if ($fallback !== null) {
                    return $fallback;
                }
            }

            return [
                'success' => true,
                'mentions' => $mentions,
                'total_mentions' => count($mentions)
            ];

        } catch (\Exception $e) {
            \Log::error('LLM web search error: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'mentions' => []
            ];
        }
    }

    /**
     * Search for social profile links using LLM function calling
     *
     * @param string $businessName
     * @param string|null $location
     * @return array
     */
    public function searchSocialLinks(string $businessName, ?string $location = null): array
    {
        try {
            $profiles = $this->searchSocialProfiles(
                $businessName,
                $this->socialPlatforms,
                null,
                $location
            );

            $urls = array_map(function ($profile) {
                return $profile['url'];
            }, $profiles);

            return array_values(array_unique($urls));
        } catch (\Exception $e) {
            \Log::warning('LLM social link search failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Search for social profile URLs by platform using LLM
     *
     * @param string $businessName
     * @param array $platforms
     * @param string|null $country
     * @param string|null $location
     * @return array
     */
    public function searchSocialProfiles(
        string $businessName,
        array $platforms,
        ?string $country = null,
        ?string $location = null
    ): array {
        $profiles = [];
        $countryCode = $this->normalizeCountry($country);

        foreach ($platforms as $platform) {
            $query = $this->buildPlatformQuery($businessName, $platform);
            if ($query === '') {
                continue;
            }

            $response = $this->executeWebSearch($query);
            if (!$response['success'] || empty($response['mentions'])) {
                continue;
            }

            $url = $this->selectProfileUrl($response['mentions'], $platform);
            if ($url === null) {
                continue;
            }

            $profiles[] = [
                'platform' => $platform,
                'url' => $url,
                'verified' => false,
                'source' => 'llm_web_search'
            ];
        }

        if (empty($profiles)) {
            return $this->fallbackSocialProfiles($businessName, $platforms, $country, $location);
        }

        return $profiles;
    }

    /**
     * Execute web search using LLM function calling
     * 
     * @param string $query
     * @return array
     */
    private function executeWebSearch(string $query): array
    {
        try {
            // Build function tools for web search
            $tools = [
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'web_search',
                        'description' => 'Search the web for information',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => [
                                    'type' => 'string',
                                    'description' => 'The search query'
                                ]
                            ],
                            'required' => ['query']
                        ]
                    ]
                ]
            ];

            // Call LLM with web_search tool
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json'
            ])->post("{$this->baseUrl}/chat/completions", [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => "Search for information about: {$query}"
                    ]
                ],
                'tools' => $tools,
                'tool_choice' => 'auto'
            ]);

            if ($response->failed()) {
                \Log::warning('LLM web search API failed: ' . $response->body());
                return [
                    'success' => false,
                    'mentions' => []
                ];
            }

            $data = $response->json();
            
            // If the LLM triggers the web_search tool, execute it
            // Note: This depends on your LLM provider's capabilities
            // Some providers return results directly, others return tool calls
            
            $mentions = $this->extractMentions($data, $query);

            return [
                'success' => true,
                'mentions' => $mentions
            ];

        } catch (\Exception $e) {
            \Log::error('LLM web search execution error: ' . $e->getMessage());
            return [
                'success' => false,
                'mentions' => []
            ];
        }
    }

    /**
     * Extract mentions from LLM response
     * 
     * @param array $data
     * @param string $query
     * @return array
     */
    private function extractMentions(array $data, string $query): array
    {
        $mentions = [];

        // Get the response content
        if (isset($data['choices'][0]['message']['content'])) {
            $content = $data['choices'][0]['message']['content'];
            
            // Parse URLs from the response
            if (preg_match_all('/https?:\/\/[^\s"<>]+/', $content, $matches)) {
                foreach ($matches[0] as $url) {
                    $mention = [
                        'url' => $url,
                        'title' => $this->extractTitleFromContent($content, $url),
                        'snippet' => $this->extractSnippetFromContent($content, $url),
                        'source' => $this->determineSource($url),
                        'source_weight' => $this->getSourceWeight($this->determineSource($url)),
                        'content' => null
                    ];
                    $mentions[] = $mention;
                }
            }
        }

        // Check for tool_calls in response (some providers return structured tool calls)
        if (isset($data['choices'][0]['message']['tool_calls'])) {
            foreach ($data['choices'][0]['message']['tool_calls'] as $toolCall) {
                if ($toolCall['function']['name'] === 'web_search') {
                    // Parse function arguments if available
                    $args = json_decode($toolCall['function']['arguments'], true);
                    if (isset($args['results'])) {
                        foreach ($args['results'] as $result) {
                            $mention = [
                                'url' => $result['link'] ?? $result['url'] ?? '',
                                'title' => $result['title'] ?? '',
                                'snippet' => $result['snippet'] ?? '',
                                'source' => $this->determineSource($result['link'] ?? $result['url'] ?? ''),
                                'source_weight' => $this->getSourceWeight($this->determineSource($result['link'] ?? $result['url'] ?? '')),
                                'content' => null
                            ];
                            
                            if (!empty($mention['url'])) {
                                $mentions[] = $mention;
                            }
                        }
                    }
                }
            }
        }

        return $mentions;
    }

    /**
     * Extract title from content
     */
    private function extractTitleFromContent(string $content, string $url): string
    {
        // Try to find text near the URL that could be a title
        if (preg_match('/\[([^\]]+)\]\(' . preg_quote($url) . '\)/', $content, $matches)) {
            return $matches[1];
        }
        return parse_url($url, PHP_URL_HOST) ?? $url;
    }

    /**
     * Extract snippet from content
     */
    private function extractSnippetFromContent(string $content, string $url): string
    {
        // Find text around the URL
        $pos = strpos($content, $url);
        if ($pos !== false) {
            $start = max(0, $pos - 100);
            $end = min(strlen($content), $pos + strlen($url) + 100);
            return substr($content, $start, $end - $start);
        }
        return '';
    }

    /**
     * Generate targeted search queries
     * 
     * @param string $businessName
     * @param string|null $location
     * @return array
     */
    private function generateSearchQueries(string $businessName, ?string $location = null): array
    {
        $locationSuffix = $location ? " {$location}" : '';

        $queries = [
            "{$businessName}{$locationSuffix}",
            "{$businessName}{$locationSuffix} reviews",
            "{$businessName} complaints",
            "{$businessName} site:yelp.com",
            "{$businessName} site:google.com/maps",
            "{$businessName} site:reddit.com",
            "{$businessName} news",
            "{$businessName} lawsuit OR fraud OR scandal",
        ];

        return array_values(array_unique($queries));
    }

    /**
     * Filter high-signal mentions
     */
    private function filterHighSignalMentions(array $mentions): array
    {
        $highSignalDomains = [
            'yelp.com',
            'google.com',
            'news.google.com',
            'trustpilot.com',
            'bbb.org',
            'reddit.com',
            'twitter.com',
            'x.com',
            'facebook.com',
            'linkedin.com',
            'instagram.com',
            'threads.net',
            'tiktok.com',
            'youtube.com',
            'prnewswire.com',
            'businesswire.com'
        ];

        $reputationKeywords = [
            'review',
            'rating',
            'complaint',
            'experience',
            'scam',
            'lawsuit',
            'fraud',
            'quality',
            'service',
            'feedback'
        ];

        $filtered = [];
        $seenUrls = [];

        foreach ($mentions as $mention) {
            if (in_array($mention['url'], $seenUrls)) {
                continue;
            }

            $url = strtolower($mention['url']);
            $text = strtolower($mention['title'] . ' ' . $mention['snippet']);

            $hasHighSignalDomain = false;
            foreach ($highSignalDomains as $domain) {
                if (strpos($url, $domain) !== false) {
                    $hasHighSignalDomain = true;
                    break;
                }
            }

            $hasReputationKeyword = false;
            foreach ($reputationKeywords as $keyword) {
                if (strpos($text, $keyword) !== false) {
                    $hasReputationKeyword = true;
                    break;
                }
            }

            if ($hasHighSignalDomain || $hasReputationKeyword) {
                $filtered[] = $mention;
                $seenUrls[] = $mention['url'];
            }
        }

        return array_slice($filtered, 0, 20);
    }

    /**
     * Determine source type from URL
     */
    private function determineSource(string $url): string
    {
        $url = strtolower($url);

        if (strpos($url, 'yelp.com') !== false || strpos($url, 'google.com/maps') !== false ||
            strpos($url, 'trustpilot.com') !== false || strpos($url, 'bbb.org') !== false) {
            return 'reviews';
        }

        if (strpos($url, 'reddit.com') !== false) {
            return 'forum';
        }

        if (strpos($url, 'twitter.com') !== false || strpos($url, 'facebook.com') !== false ||
            strpos($url, 'instagram.com') !== false || strpos($url, 'tiktok.com') !== false ||
            strpos($url, 'linkedin.com') !== false || strpos($url, 'x.com') !== false ||
            strpos($url, 'threads.net') !== false) {
            return 'social';
        }

        if (strpos($url, 'news.google.com') !== false || strpos($url, 'cnn.com') !== false || 
            strpos($url, 'reuters.com') !== false || strpos($url, 'apnews.com') !== false) {
            return 'news';
        }

        return 'blog';
    }

    /**
     * Get source weight
     */
    private function getSourceWeight(string $source): float
    {
        return match ($source) {
            'news' => 1.0,
            'reviews' => 0.8,
            'forum' => 0.6,
            'social' => 0.5,
            'blog' => 0.4,
            default => 0.4
        };
    }

    /**
     * Build platform query
     */
    private function buildPlatformQuery(string $businessName, string $platform): string
    {
        $businessName = trim($businessName);
        if ($businessName === '') {
            return '';
        }

        return match ($platform) {
            'x' => "{$businessName} X account profile",
            'youtube' => "{$businessName} YouTube channel",
            'tiktok' => "{$businessName} TikTok account",
            default => "{$businessName} {$platform} profile"
        };
    }

    /**
     * Normalize country code
     */
    private function normalizeCountry(?string $country): string
    {
        $country = $country ? strtolower(trim($country)) : '';
        if ($country === '' || strlen($country) !== 2) {
            return 'us';
        }
        return $country;
    }

    /**
     * Select profile URL from mentions
     */
    private function selectProfileUrl(array $mentions, string $platform): ?string
    {
        foreach ($mentions as $mention) {
            $url = $mention['url'] ?? '';
            if ($url === '') {
                continue;
            }

            if ($this->isLikelySocialProfile($platform, $url)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Check if URL is likely a social profile
     */
    private function isLikelySocialProfile(string $platform, string $url): bool
    {
        $lower = strtolower($url);
        $parts = parse_url($lower);
        $path = $parts['path'] ?? '';
        $host = $parts['host'] ?? '';

        if (!$this->matchesPlatformDomain($platform, $host)) {
            return false;
        }

        if ($platform === 'x') {
            return !str_contains($path, '/status/');
        }

        if ($platform === 'facebook') {
            return !preg_match('#/(posts|photos|videos|watch|permalink|story)\b#', $path);
        }

        if ($platform === 'instagram') {
            return !preg_match('#/(p|reel|tv|stories)/#', $path);
        }

        if ($platform === 'tiktok') {
            return (bool) preg_match('#/@[^/]+/?$#', $path);
        }

        if ($platform === 'linkedin') {
            return (bool) preg_match('#/(company|in|school|showcase)/#', $path)
                && !preg_match('#/(feed|posts|pulse)/#', $path);
        }

        if ($platform === 'youtube') {
            return (bool) preg_match('#/(channel|c|@|user)#', $path) && !str_contains($path, '/watch');
        }

        if ($platform === 'threads') {
            return !preg_match('#/post/#', $path);
        }

        return false;
    }

    /**
     * Match platform domain
     */
    private function matchesPlatformDomain(string $platform, string $host): bool
    {
        $domains = match ($platform) {
            'x' => ['x.com', 'twitter.com'],
            'facebook' => ['facebook.com'],
            'instagram' => ['instagram.com'],
            'linkedin' => ['linkedin.com'],
            'tiktok' => ['tiktok.com'],
            'youtube' => ['youtube.com'],
            'threads' => ['threads.net'],
            default => []
        };

        foreach ($domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fetch and extract content from URL
     */
    public function extractContent(string $url): ?string
    {
        try {
            $response = Http::timeout(10)->get($url);

            if ($response->failed()) {
                return null;
            }

            $html = $response->body();
            $text = strip_tags($html);
            $text = preg_replace('/\s+/', ' ', $text);

            return substr(trim($text), 0, 2000);

        } catch (\Exception $e) {
            \Log::warning('Failed to extract content from ' . $url . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fallback mention search using Serper when LLM search produces no results.
     */
    private function fallbackSearch(string $businessName, ?string $location = null): ?array
    {
        $fallbackService = $this->getSerperFallbackService();
        if ($fallbackService === null) {
            return null;
        }

        $fallback = $fallbackService->search($businessName, $location);
        if (!($fallback['success'] ?? false) || empty($fallback['mentions'])) {
            return null;
        }

        \Log::info('LLM web search fell back to Serper for mentions', [
            'business_name' => $businessName,
            'location' => $location,
            'mentions' => count($fallback['mentions'] ?? [])
        ]);

        return $fallback;
    }

    /**
     * Fallback social profile search using Serper when LLM search produces no results.
     */
    private function fallbackSocialProfiles(
        string $businessName,
        array $platforms,
        ?string $country,
        ?string $location
    ): array {
        $fallbackService = $this->getSerperFallbackService();
        if ($fallbackService === null) {
            return [];
        }

        $profiles = $fallbackService->searchSocialProfiles($businessName, $platforms, $country, $location);
        if (empty($profiles)) {
            return [];
        }

        \Log::info('LLM web search fell back to Serper for social profiles', [
            'business_name' => $businessName,
            'location' => $location,
            'profiles' => count($profiles)
        ]);

        return $profiles;
    }

    /**
     * Return Serper fallback service when enabled and configured.
     */
    private function getSerperFallbackService(): ?SerperSearchService
    {
        if (!$this->fallbackToSerper) {
            return null;
        }

        $serperApiKey = trim((string) config('services.serper.api_key', ''));
        if ($serperApiKey === '') {
            return null;
        }

        return app(SerperSearchService::class);
    }
}
