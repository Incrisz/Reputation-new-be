<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class SerperSearchService
{
    private string $serperApiKey;
    private string $serperBaseUrl = 'https://google.serper.dev/search';

    public function __construct()
    {
        $this->serperApiKey = config('services.serper.api_key');
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
     * Generate targeted search queries
     * 
     * @param string $businessName
     * @param string|null $location
     * @return array
     */
    private function generateSearchQueries(string $businessName, ?string $location = null): array
    {
        $queries = [
            "{$businessName} {$location}",
            "{$businessName} {$location} reviews",
            "{$businessName} complaints",
            "{$businessName} site:yelp.com",
            "{$businessName} site:google.com/maps",
            "{$businessName} site:reddit.com",
            "{$businessName} news",
            "{$businessName} lawsuit OR fraud OR scandal"
        ];

        // Remove location from queries where not applicable
        if (!$location) {
            $queries = [
                $businessName,
                "{$businessName} reviews",
                "{$businessName} complaints",
                "{$businessName} site:yelp.com",
                "{$businessName} site:google.com/maps",
                "{$businessName} site:reddit.com",
                "{$businessName} news",
                "{$businessName} lawsuit OR fraud OR scandal"
            ];
        }

        return $queries;
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
            'trustpilot.com',
            'bbb.org',
            'reddit.com',
            'twitter.com',
            'facebook.com',
            'linkedin.com',
            'instagram.com',
            'tiktok.com',
            'youtube.com'
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
            strpos($url, 'nytimes.com') !== false) {
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
            strpos($url, 'linkedin.com') !== false) {
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
