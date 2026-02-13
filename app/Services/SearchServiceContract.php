<?php

namespace App\Services;

interface SearchServiceContract
{
    /**
     * Search for business mentions.
     *
     * @param string $businessName
     * @param string|null $location
     * @return array
     */
    public function search(string $businessName, ?string $location = null): array;

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
    ): array;

    /**
     * Search for likely social profile links.
     *
     * @param string $businessName
     * @param string|null $location
     * @return array
     */
    public function searchSocialLinks(string $businessName, ?string $location = null): array;

    /**
     * Fetch and extract textual content from URL.
     *
     * @param string $url
     * @return string|null
     */
    public function extractContent(string $url): ?string;
}
