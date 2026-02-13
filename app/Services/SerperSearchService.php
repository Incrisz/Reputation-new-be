<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class SerperSearchService implements SearchServiceContract
{
    private ?string $serperApiKey;
    private string $serperBaseUrl = 'https://google.serper.dev/search';
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
        $this->serperApiKey = config('services.serper.api_key') ?? '';
    }

    /**
     * Search for business mentions
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
                $results = $this->executeQuery($query);
                if ($results['success']) {
                    $mentions = array_merge($mentions, $results['mentions']);
                }
            }

            // Remove duplicates and limit to high-impact mentions
            $mentions = $this->filterHighSignalMentions($mentions);

            return [
                'success' => true,
                'mentions' => $mentions,
                'total_mentions' => count($mentions)
            ];

        } catch (\Exception $e) {
            \Log::error('Serper search error: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'mentions' => []
            ];
        }
    }

    /**
     * Search for likely social profile links when a website is unavailable.
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
            \Log::warning('Serper social link search failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Search for social profile URLs by platform.
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
        if (empty($this->serperApiKey)) {
            \Log::warning('Serper API key not configured');
            return [];
        }

        $profiles = [];
        $countryCode = $this->normalizeCountry($country);

        foreach ($platforms as $platform) {
            $query = $this->buildPlatformQuery($businessName, $platform);
            if ($query === '') {
                continue;
            }

            $response = $this->executeRawQuery($query, $countryCode);
            if (!$response['success']) {
                continue;
            }

            $url = $this->selectProfileUrl($response['organic'] ?? [], $platform);
            if ($url === null) {
                continue;
            }

            $profiles[] = [
                'platform' => $platform,
                'url' => $url,
                'verified' => false,
                'source' => 'serper'
            ];
        }

        return $profiles;
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

        $queries = array_merge(
            $queries,
            $this->getNewsQueries($businessName),
            $this->getSocialQueries($businessName)
        );

        return array_values(array_unique($queries));
    }

    /**
     * Execute search query
     * 
     * @param string $query
     * @return array
     */
    private function executeQuery(string $query): array
    {
        try {
            $response = Http::withHeaders([
                'X-API-KEY' => $this->serperApiKey,
                'Content-Type' => 'application/json'
            ])->post($this->serperBaseUrl, [
                'q' => $query,
                'num' => 10,
                'gl' => 'us',
                'hl' => 'en'
            ]);

            if ($response->failed()) {
                return [
                    'success' => false,
                    'mentions' => []
                ];
            }

            $data = $response->json();
            $mentions = [];

            // Extract organic results
            if (isset($data['organic']) && is_array($data['organic'])) {
                foreach ($data['organic'] as $result) {
                    $mention = [
                        'url' => $result['link'] ?? '',
                        'title' => $result['title'] ?? '',
                        'snippet' => $result['snippet'] ?? '',
                        'source' => $this->determineSource($result['link'] ?? ''),
                        'source_weight' => $this->getSourceWeight($this->determineSource($result['link'] ?? '')),
                        'content' => null // Will be populated by deep-read
                    ];

                    $mentions[] = $mention;
                }
            }

            // Extract news results when present
            if (isset($data['news']) && is_array($data['news'])) {
                foreach ($data['news'] as $result) {
                    $mention = [
                        'url' => $result['link'] ?? '',
                        'title' => $result['title'] ?? '',
                        'snippet' => $result['snippet'] ?? '',
                        'source' => $this->determineSource($result['link'] ?? ''),
                        'source_weight' => $this->getSourceWeight($this->determineSource($result['link'] ?? '')),
                        'content' => null
                    ];

                    $mentions[] = $mention;
                }
            }

            return [
                'success' => true,
                'mentions' => $mentions
            ];

        } catch (\Exception $e) {
            \Log::error('Serper query error: ' . $e->getMessage());

            return [
                'success' => false,
                'mentions' => []
            ];
        }
    }

    /**
     * Filter high-signal mentions
     * 
     * @param array $mentions
     * @return array
     */
    private function filterHighSignalMentions(array $mentions): array
    {
        // Define high-signal domains
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
            // Skip if URL already processed
            if (in_array($mention['url'], $seenUrls)) {
                continue;
            }

            $url = strtolower($mention['url']);
            $text = strtolower($mention['title'] . ' ' . $mention['snippet']);

            // Check domain
            $hasHighSignalDomain = false;
            foreach ($highSignalDomains as $domain) {
                if (strpos($url, $domain) !== false) {
                    $hasHighSignalDomain = true;
                    break;
                }
            }

            // Check reputation keywords
            $hasReputationKeyword = false;
            foreach ($reputationKeywords as $keyword) {
                if (strpos($text, $keyword) !== false) {
                    $hasReputationKeyword = true;
                    break;
                }
            }

            // Include if high-signal domain OR has reputation keyword
            if ($hasHighSignalDomain || $hasReputationKeyword) {
                $filtered[] = $mention;
                $seenUrls[] = $mention['url'];
            }
        }

        // Return top 20 high-impact mentions
        return array_slice($filtered, 0, 20);
    }

    /**
     * Determine source type from URL
     * 
     * @param string $url
     * @return string
     */
    private function determineSource(string $url): string
    {
        $url = strtolower($url);

        // News publishers
        if (strpos($url, 'cnn.com') !== false || strpos($url, 'bbc.com') !== false ||
            strpos($url, 'reuters.com') !== false || strpos($url, 'apnews.com') !== false ||
            strpos($url, 'forbes.com') !== false || strpos($url, 'wsj.com') !== false ||
            strpos($url, 'nytimes.com') !== false || strpos($url, 'news.google.com') !== false ||
            strpos($url, 'prnewswire.com') !== false || strpos($url, 'businesswire.com') !== false) {
            return 'news';
        }

        // Review platforms
        if (strpos($url, 'yelp.com') !== false || strpos($url, 'google.com/maps') !== false ||
            strpos($url, 'trustpilot.com') !== false || strpos($url, 'bbb.org') !== false) {
            return 'reviews';
        }

        // Forums
        if (strpos($url, 'reddit.com') !== false) {
            return 'forum';
        }

        // Social
        if (strpos($url, 'twitter.com') !== false || strpos($url, 'facebook.com') !== false ||
            strpos($url, 'instagram.com') !== false || strpos($url, 'tiktok.com') !== false ||
            strpos($url, 'linkedin.com') !== false || strpos($url, 'x.com') !== false ||
            strpos($url, 'threads.net') !== false) {
            return 'social';
        }

        // Default to blog/personal
        return 'blog';
    }

    /**
     * Get source weight based on source type
     * 
     * @param string $source
     * @return float
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
     * Build targeted news queries
     *
     * @param string $businessName
     * @return array
     */
    private function getNewsQueries(string $businessName): array
    {
        return [
            "{$businessName} site:news.google.com",
            "{$businessName} site:reuters.com OR site:apnews.com OR site:bbc.com OR site:cnn.com",
            "{$businessName} site:prnewswire.com OR site:businesswire.com",
        ];
    }

    /**
     * Build targeted social queries
     *
     * @param string $businessName
     * @return array
     */
    private function getSocialQueries(string $businessName): array
    {
        return [
            "{$businessName} site:twitter.com OR site:x.com",
            "{$businessName} site:facebook.com",
            "{$businessName} site:linkedin.com",
            "{$businessName} site:instagram.com",
            "{$businessName} site:tiktok.com",
            "{$businessName} site:threads.net",
            "{$businessName} site:youtube.com",
        ];
    }

    private function buildSocialQueries(string $businessName, ?string $location = null): array
    {
        $locationSuffix = $location ? " {$location}" : '';
        $queries = [
            "{$businessName}{$locationSuffix} site:twitter.com OR site:x.com",
            "{$businessName}{$locationSuffix} site:facebook.com",
            "{$businessName}{$locationSuffix} site:linkedin.com",
            "{$businessName}{$locationSuffix} site:instagram.com",
            "{$businessName}{$locationSuffix} site:tiktok.com",
            "{$businessName}{$locationSuffix} site:threads.net",
            "{$businessName}{$locationSuffix} site:youtube.com",
        ];

        return array_values(array_unique($queries));
    }

    private function buildPlatformQuery(string $businessName, string $platform): string
    {
        $businessName = trim($businessName);
        if ($businessName === '') {
            return '';
        }

        return match ($platform) {
            'x' => "{$businessName} X",
            'youtube' => "{$businessName} YouTube channel",
            'tiktok' => "{$businessName} TikTok",
            default => "{$businessName} site:" . $this->getPlatformDomain($platform)
        };
    }

    private function getPlatformDomain(string $platform): string
    {
        return match ($platform) {
            'facebook' => 'facebook.com',
            'instagram' => 'instagram.com',
            'linkedin' => 'linkedin.com',
            'tiktok' => 'tiktok.com',
            'x' => 'x.com',
            'youtube' => 'youtube.com',
            'threads' => 'threads.net',
            default => ''
        };
    }

    private function normalizeCountry(?string $country): string
    {
        $country = $country ? strtolower(trim($country)) : '';
        if ($country === '' || strlen($country) !== 2) {
            return 'us';
        }
        return $country;
    }

    private function executeRawQuery(string $query, string $country): array
    {
        try {
            $response = Http::withHeaders([
                'X-API-KEY' => $this->serperApiKey,
                'Content-Type' => 'application/json'
            ])->post($this->serperBaseUrl, [
                'q' => $query,
                'num' => 10,
                'gl' => $country,
                'hl' => 'en'
            ]);

            if ($response->failed()) {
                return [
                    'success' => false
                ];
            }

            $data = $response->json();

            return [
                'success' => true,
                'organic' => $data['organic'] ?? []
            ];
        } catch (\Exception $e) {
            \Log::warning('Serper raw query failed: ' . $e->getMessage());
            return [
                'success' => false
            ];
        }
    }

    private function selectProfileUrl(array $organicResults, string $platform): ?string
    {
        foreach ($organicResults as $result) {
            $url = $result['link'] ?? '';
            if ($url === '') {
                continue;
            }

            if ($this->isLikelySocialProfile($platform, $url)) {
                return $url;
            }
        }

        return null;
    }

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

    private function matchesPlatformDomain(string $platform, string $host): bool
    {
        $domains = [];

        if ($platform === 'x') {
            $domains = ['x.com', 'twitter.com'];
        } else {
            $domain = $this->getPlatformDomain($platform);
            if ($domain !== '') {
                $domains[] = $domain;
            }
        }

        foreach ($domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fetch and extract content from URL
     * 
     * @param string $url
     * @return string|null
     */
    public function extractContent(string $url): ?string
    {
        try {
            $response = Http::timeout(10)->get($url);

            if ($response->failed()) {
                return null;
            }

            $html = $response->body();

            // Strip HTML tags and extract text
            $text = strip_tags($html);

            // Remove extra whitespace
            $text = preg_replace('/\s+/', ' ', $text);

            // Limit to first 2000 characters to avoid token overload
            return substr(trim($text), 0, 2000);

        } catch (\Exception $e) {
            \Log::warning('Failed to extract content from ' . $url . ': ' . $e->getMessage());
            return null;
        }
    }
}
