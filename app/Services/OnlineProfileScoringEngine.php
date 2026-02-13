<?php

namespace App\Services;

class OnlineProfileScoringEngine
{
    /**
     * Calculate visibility score based on Google rating and review volume.
     *
     * @param float|null $rating
     * @param int|null $reviewCount
     * @return array
     */
    public function calculate(?float $rating, ?int $reviewCount): array
    {
        if ($rating === null && $reviewCount === null) {
            return [
                'visibility_score' => null
            ];
        }

        $ratingValue = $rating ?? 0.0;
        $ratingValue = max(0.0, min(5.0, $ratingValue));

        $countValue = $reviewCount ?? 0;
        $countValue = max(0, $countValue);

        // Rating: 1-5 -> 0-1. Review volume: log scale to avoid over-weighting large counts.
        $ratingNormalized = $ratingValue / 5.0;
        $volumeNormalized = min(1.0, log10($countValue + 1) / 3.0);

        $score = ($ratingNormalized * 0.7 + $volumeNormalized * 0.3) * 100;

        return [
            'visibility_score' => (int) round($score)
        ];
    }
}
