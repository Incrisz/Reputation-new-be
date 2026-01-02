<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class BusinessVerificationService
{
    /**
     * Verify business via website or phone+location
     * 
     * @param array $data
     * @return array
     */
    public function verify(array $data): array
    {
        $hasWebsite = !empty($data['website']);
        $hasPhone = !empty($data['phone']);
        $hasLocation = !empty($data['location']);

        // Path A: Website verification
        if ($hasWebsite) {
            return $this->verifyByWebsite(
                $data['website'],
                $data['business_name'] ?? null,
                $data['industry'] ?? null
            );
        }

        // Path B: Phone + Location verification
        if ($hasPhone && $hasLocation) {
            return $this->verifyByPhoneLocation(
                $data['phone'],
                $data['location'],
                $data['business_name'] ?? null
            );
        }

        return [
            'success' => false,
            'error_code' => 'AMBIGUOUS_BUSINESS',
            'message' => 'Business name alone is too ambiguous',
            'details' => 'Provide website OR (phone + location)',
            'http_code' => 422
        ];
    }

    /**
     * Verify business by website
     * 
     * @param string $website
     * @param string|null $businessName
     * @param string|null $industry
     * @return array
     */
    private function verifyByWebsite(?string $website, ?string $businessName = null, ?string $industry = null): array
    {
        try {
            $website = trim((string) $website);
            $candidateUrls = $this->buildWebsiteCandidates($website);
            $successfulResponse = null;

            foreach ($candidateUrls as $candidateUrl) {
                try {
                    $response = Http::timeout(10)
                        ->withHeaders([
                            'User-Agent' => 'Mozilla/5.0 (compatible; ReputationAI/1.0)',
                        ])
                        ->get($candidateUrl);

                    \Log::info('Website verification response', [
                        'url' => $candidateUrl,
                        'status' => $response->status(),
                    ]);

                    if ($response->successful()) {
                        $successfulResponse = $response;
                        $website = $candidateUrl;
                        break;
                    }
                } catch (\Exception $e) {
                    \Log::warning('Website verification request failed', [
                        'url' => $candidateUrl,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }

            if (!$successfulResponse) {
                return [
                    'success' => false,
                    'error_code' => 'INVALID_DOMAIN',
                    'message' => 'Domain is not accessible',
                    'details' => 'Please check the website URL',
                    'http_code' => 400
                ];
            }

            // Extract business name from HTML if not provided
            $extractedName = $businessName ?? $this->extractBusinessNameFromHTML($successfulResponse->body());

            if (!$extractedName) {
                return [
                    'success' => false,
                    'error_code' => 'DOMAIN_VERIFICATION_FAILED',
                    'message' => 'Could not verify business name on website',
                    'details' => 'Please ensure the business name appears on your website',
                    'http_code' => 403
                ];
            }

            return [
                'success' => true,
                'business_data' => [
                    'business_name' => $extractedName,
                    'verified_website' => $website,
                    'verified_location' => null,
                    'verified_phone' => null,
                    'industry' => $industry
                ]
            ];

        } catch (\Exception $e) {
            \Log::error('Website verification error: ' . $e->getMessage());

            return [
                'success' => false,
                'error_code' => 'INVALID_DOMAIN',
                'message' => 'Failed to verify domain',
                'details' => 'Please check the website URL',
                'http_code' => 400
            ];
        }
    }

    /**
     * Verify business by phone + location
     * 
     * @param string $phone
     * @param string $location
     * @param string|null $businessName
     * @return array
     */
    private function verifyByPhoneLocation(string $phone, string $location, ?string $businessName = null): array
    {
        try {
            // Phone is the unique identifier
            // Validate phone format
            $cleanPhone = preg_replace('/[^\d\+]/', '', $phone);
            if (strlen($cleanPhone) < 10) {
                return [
                    'success' => false,
                    'error_code' => 'INVALID_IDENTIFICATION',
                    'message' => 'Invalid phone number format',
                    'details' => 'Please provide a valid phone number',
                    'http_code' => 422
                ];
            }

            // Validate location format
            if (strlen($location) < 3) {
                return [
                    'success' => false,
                    'error_code' => 'INVALID_IDENTIFICATION',
                    'message' => 'Invalid location format',
                    'details' => 'Please provide a valid location (city, state)',
                    'http_code' => 422
                ];
            }

            // If no business name provided, use generic placeholder for search
            $searchName = $businessName ?? 'Business';

            return [
                'success' => true,
                'business_data' => [
                    'business_name' => $businessName ?? 'Business at ' . $location,
                    'verified_website' => null,
                    'verified_location' => $location,
                    'verified_phone' => $cleanPhone,
                    'industry' => null
                ]
            ];

        } catch (\Exception $e) {
            \Log::error('Phone+Location verification error: ' . $e->getMessage());

            return [
                'success' => false,
                'error_code' => 'ANALYSIS_ERROR',
                'message' => 'Failed to verify business',
                'details' => null,
                'http_code' => 500
            ];
        }
    }

    /**
     * Build candidate URLs for website verification.
     *
     * @param string $website
     * @return array
     */
    private function buildWebsiteCandidates(string $website): array
    {
        if ($website === '') {
            return [];
        }

        if (preg_match('/^https?:\/\//i', $website)) {
            $urls = [$website];
            if (stripos($website, 'https://') === 0) {
                $urls[] = 'http://' . substr($website, 8);
            } else {
                $urls[] = 'https://' . substr($website, 7);
            }
        } else {
            $urls = [
                'https://' . $website,
                'http://' . $website,
            ];
        }

        return array_values(array_unique($urls));
    }

    /**
     * Extract business name from HTML
     * 
     * @param string $html
     * @return string|null
     */
    private function extractBusinessNameFromHTML(string $html): ?string
    {
        // Try to extract from title tag
        if (preg_match('/<title>([^<]+)<\/title>/i', $html, $matches)) {
            $title = trim($matches[1]);
            // Remove common suffixes
            $title = preg_replace('/\s*[-|]\s*(Home|Official|Website|Site)$/i', '', $title);
            if (strlen($title) > 2 && strlen($title) < 100) {
                return $title;
            }
        }

        // Try to extract from h1 tag
        if (preg_match('/<h1[^>]*>([^<]+)<\/h1>/i', $html, $matches)) {
            $name = trim($matches[1]);
            if (strlen($name) > 2 && strlen($name) < 100) {
                return $name;
            }
        }

        // Try to extract from meta og:site_name
        if (preg_match('/<meta\s+property=["\'](og:)?site_name["\']\s+content=["\'](.*?)["\']/i', $html, $matches)) {
            $name = trim($matches[2]);
            if (strlen($name) > 2 && strlen($name) < 100) {
                return $name;
            }
        }

        return null;
    }
}
