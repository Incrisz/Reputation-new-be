<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GooglePlacesService
{
    private string $apiKey;
    private string $baseUrl = 'https://maps.googleapis.com/maps/api/place';

    public function __construct()
    {
        $this->apiKey = (string) config('services.google_places.api_key');
    }

    /**
     * Fetch Google Places profile data for a business.
     *
     * @param string|null $businessName
     * @param string|null $location
     * @param string|null $phone
     * @param string|null $website
     * @param string|null $placeId
     * @return array
     */
    public function fetchProfile(
        ?string $businessName,
        ?string $location,
        ?string $phone,
        ?string $website,
        ?string $placeId = null
    ): array {
        if ($this->apiKey === '') {
            return [
                'success' => false,
                'found' => false,
                'reason' => 'missing_api_key'
            ];
        }

        if ($placeId !== null && $placeId !== '') {
            $details = $this->placeDetails($placeId);
            if (!$details['success']) {
                return [
                    'success' => false,
                    'found' => false,
                    'reason' => 'details_failed'
                ];
            }
            $search = [
                'place_id' => $placeId,
                'name' => $details['name'] ?? null,
                'address' => $details['address'] ?? null,
                'rating' => $details['rating'] ?? null,
                'review_count' => $details['review_count'] ?? null
            ];
        } else {
            $query = $this->buildQuery($businessName, $location, $phone, $website);
            if ($query === '') {
                return [
                    'success' => false,
                    'found' => false,
                    'reason' => 'missing_query'
                ];
            }

            $search = $this->textSearch($query);
            if (!$search['success'] || empty($search['place_id'])) {
                return [
                    'success' => false,
                    'found' => false,
                    'reason' => 'not_found'
                ];
            }

            $details = $this->placeDetails($search['place_id']);
        }

        if (!$details['success']) {
            return [
                'success' => false,
                'found' => false,
                'reason' => 'details_failed'
            ];
        }

        $websiteUrl = $details['website'] ?? $website;
        $socialProfiles = $websiteUrl ? $this->extractSocialProfilesFromWebsite($websiteUrl) : [];
        $socialLinks = array_values(array_unique(array_map(function ($profile) {
            return $profile['url'];
        }, $socialProfiles)));

        return [
            'success' => true,
            'found' => true,
            'place_id' => $search['place_id'],
            'name' => $details['name'] ?? ($search['name'] ?? null),
            'address' => $details['address'] ?? ($search['address'] ?? null),
            'rating' => $details['rating'] ?? ($search['rating'] ?? null),
            'review_count' => $details['review_count'] ?? ($search['review_count'] ?? null),
            'reviews' => $details['reviews'] ?? [],
            'website' => $websiteUrl,
            'maps_url' => $details['maps_url'] ?? null,
            'social_links' => $socialLinks,
            'social_profiles' => $socialProfiles,
            'source' => 'google_places'
        ];
    }

    /**
     * Search Google Places and return candidate matches.
     *
     * @param string|null $businessName
     * @param string|null $location
     * @param string|null $phone
     * @param string|null $website
     * @return array
     */
    public function searchCandidates(
        ?string $businessName,
        ?string $location,
        ?string $phone,
        ?string $website
    ): array {
        if ($this->apiKey === '') {
            return [
                'success' => false,
                'reason' => 'missing_api_key'
            ];
        }

        $query = $this->buildQuery($businessName, $location, $phone, $website);
        if ($query === '') {
            return [
                'success' => false,
                'reason' => 'missing_query'
            ];
        }

        $results = $this->textSearchResults($query);
        if (!$results['success']) {
            return [
                'success' => false,
                'reason' => $results['reason'] ?? 'search_failed'
            ];
        }

        return [
            'success' => true,
            'candidates' => $this->normalizeCandidates($results['results'] ?? [])
        ];
    }

    /**
     * Build a search query from available business identifiers.
     */
    private function buildQuery(?string $businessName, ?string $location, ?string $phone, ?string $website): string
    {
        $parts = [];

        if (!empty($businessName)) {
            $parts[] = $businessName;
        }

        if (!empty($location)) {
            $parts[] = $location;
        }

        if (!empty($phone)) {
            $parts[] = $phone;
        }

        if (empty($parts) && !empty($website)) {
            $parts[] = $this->normalizeWebsiteForQuery($website);
        }

        return trim(implode(' ', $parts));
    }

    /**
     * Perform a text search on Google Places and return the top match.
     */
    private function textSearch(string $query): array
    {
        $results = $this->textSearchResults($query);
        if (!$results['success'] || empty($results['results'][0])) {
            return [
                'success' => false
            ];
        }

        $result = $results['results'][0];

        return [
            'success' => true,
            'place_id' => $result['place_id'] ?? null,
            'name' => $result['name'] ?? null,
            'address' => $result['formatted_address'] ?? null,
            'rating' => $result['rating'] ?? null,
            'review_count' => $result['user_ratings_total'] ?? null
        ];
    }

    /**
     * Perform a text search on Google Places and return the raw results.
     */
    private function textSearchResults(string $query): array
    {
        try {
            $response = Http::timeout(10)->get(
                $this->baseUrl . '/textsearch/json',
                [
                    'query' => $query,
                    'key' => $this->apiKey
                ]
            );

            if ($response->failed()) {
                return [
                    'success' => false,
                    'reason' => 'request_failed'
                ];
            }

            $data = $response->json();
            $status = $data['status'] ?? '';

            if ($status === 'ZERO_RESULTS') {
                return [
                    'success' => true,
                    'results' => []
                ];
            }

            if ($status !== 'OK') {
                return [
                    'success' => false,
                    'reason' => $status !== '' ? strtolower($status) : 'unknown_status'
                ];
            }

            return [
                'success' => true,
                'results' => $data['results'] ?? []
            ];
        } catch (\Exception $e) {
            \Log::warning('Google Places text search failed: ' . $e->getMessage());
            return [
                'success' => false,
                'reason' => 'exception'
            ];
        }
    }

    /**
     * Fetch detailed place data including reviews and website.
     */
    private function placeDetails(string $placeId): array
    {
        try {
            $response = Http::timeout(10)->get(
                $this->baseUrl . '/details/json',
                [
                    'place_id' => $placeId,
                    'fields' => implode(',', [
                        'place_id',
                        'name',
                        'formatted_address',
                        'formatted_phone_number',
                        'international_phone_number',
                        'website',
                        'url',
                        'rating',
                        'user_ratings_total',
                        'reviews'
                    ]),
                    'key' => $this->apiKey
                ]
            );

            if ($response->failed()) {
                return [
                    'success' => false
                ];
            }

            $data = $response->json();
            $status = $data['status'] ?? '';
            $result = $data['result'] ?? [];

            if ($status !== 'OK' || empty($result)) {
                return [
                    'success' => false
                ];
            }

            return [
                'success' => true,
                'place_id' => $result['place_id'] ?? null,
                'name' => $result['name'] ?? null,
                'address' => $result['formatted_address'] ?? null,
                'phone' => $result['formatted_phone_number'] ?? null,
                'international_phone' => $result['international_phone_number'] ?? null,
                'website' => $result['website'] ?? null,
                'maps_url' => $result['url'] ?? null,
                'rating' => $result['rating'] ?? null,
                'review_count' => $result['user_ratings_total'] ?? null,
                'reviews' => $this->normalizeReviews($result['reviews'] ?? [])
            ];
        } catch (\Exception $e) {
            \Log::warning('Google Places details failed: ' . $e->getMessage());
            return [
                'success' => false
            ];
        }
    }

    /**
     * Extract social profiles from a business website.
     */
    public function extractSocialProfilesFromWebsite(string $websiteUrl): array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; ReputationAI/1.0)'
                ])
                ->get($websiteUrl);

            if ($response->failed()) {
                return [];
            }

            $html = $response->body();
            preg_match_all('/href\\s*=\\s*["\\\']([^"\\\']+)["\\\']/i', $html, $matches);

            $platforms = [
                'facebook' => 'facebook.com',
                'instagram' => 'instagram.com',
                'linkedin' => 'linkedin.com',
                'tiktok' => 'tiktok.com',
                'x' => 'x.com',
                'twitter' => 'twitter.com',
                'youtube' => 'youtube.com',
                'threads' => 'threads.net'
            ];

            $profiles = [];
            foreach ($matches[1] as $href) {
                $href = trim($href);
                if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
                    continue;
                }

                $normalized = $this->normalizeLink($href, $websiteUrl);
                if ($normalized === null) {
                    continue;
                }

                $host = parse_url($normalized, PHP_URL_HOST);
                if (!$host) {
                    continue;
                }

                foreach ($platforms as $platform => $domain) {
                    if (str_contains($host, $domain)) {
                        $key = $platform === 'twitter' ? 'x' : $platform;
                        if (!isset($profiles[$key])) {
                            $profiles[$key] = [
                                'platform' => $key,
                                'url' => $normalized,
                                'verified' => true,
                                'source' => 'website'
                            ];
                        }
                        break;
                    }
                }
            }

            return array_values($profiles);
        } catch (\Exception $e) {
            \Log::warning('Social link extraction failed: ' . $e->getMessage());
            return [];
        }
    }

    private function normalizeLink(string $href, string $baseUrl): ?string
    {
        if (str_starts_with($href, '//')) {
            return 'https:' . $href;
        }

        if (preg_match('/^https?:\\/\\//i', $href)) {
            return $href;
        }

        if (str_starts_with($href, '/')) {
            $base = $this->getBaseUrl($baseUrl);
            return $base ? $base . $href : null;
        }

        return null;
    }

    private function getBaseUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (empty($parts['host'])) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'https';
        return $scheme . '://' . $parts['host'];
    }

    private function normalizeWebsiteForQuery(string $website): string
    {
        $website = preg_replace('/^https?:\\/\\//i', '', $website);
        return rtrim((string) $website, '/');
    }

    private function normalizeReviews(array $reviews): array
    {
        $normalized = [];

        foreach ($reviews as $review) {
            $normalized[] = [
                'author' => $review['author_name'] ?? null,
                'rating' => $review['rating'] ?? null,
                'text' => $review['text'] ?? null,
                'relative_time' => $review['relative_time_description'] ?? null
            ];
        }

        return array_slice($normalized, 0, 5);
    }

    private function normalizeCandidates(array $results): array
    {
        $candidates = [];

        foreach ($results as $result) {
            $candidates[] = [
                'place_id' => $result['place_id'] ?? null,
                'name' => $result['name'] ?? null,
                'address' => $result['formatted_address'] ?? null,
                'rating' => $result['rating'] ?? null,
                'review_count' => $result['user_ratings_total'] ?? null
            ];
        }

        return $candidates;
    }
}
