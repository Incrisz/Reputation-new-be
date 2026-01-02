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
