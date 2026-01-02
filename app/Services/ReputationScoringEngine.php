<?php

namespace App\Services;

class ReputationScoringEngine
{
    /**
     * Calculate reputation score based on mentions and sentiment
     * 
     * @param array $mentions
     * @param array $sentimentAnalysis
     * @return array
     */
    public function calculateScore(array $mentions, array $sentimentAnalysis): array
    {
        $baseScore = 50;
        $adjustedScore = $baseScore;

        // Source weighting mapping
        $sourceWeights = [
            'news' => 1.0,
            'reviews' => 0.8,
            'forum' => 0.6,
            'social' => 0.5,
            'blog' => 0.4
        ];

        if (empty($mentions)) {
            return [
                'reputation_score' => 50,
                'calculation' => [
                    'base' => 50,
                    'adjustments' => 0
                ]
            ];
        }

        $totalAdjustment = 0;

        // Process each mention
        foreach ($mentions as $mention) {
            $weight = $mention['source_weight'] ?? 0.4;

            // Determine sentiment impact
            $sentiment = $this->getMentionSentiment($mention, $sentimentAnalysis);
            
            $sentimentValue = match ($sentiment) {
                'positive' => 1,
                'negative' => -1,
                'neutral' => 0,
                default => 0
            };

            // Calculate weighted impact
            $sentimentImpact = $sentimentValue * $weight;

            // Apply sentiment multiplier (±5 points)
            if ($sentimentValue > 0) {
                $sentimentImpact += (5 * $weight);
            } else if ($sentimentValue < 0) {
                $sentimentImpact -= (5 * $weight);
            }

            $totalAdjustment += $sentimentImpact;
        }

        // Average the adjustment across mentions
        $averageAdjustment = $totalAdjustment / count($mentions);
        $adjustedScore = $baseScore + $averageAdjustment;

        // Apply theme adjustments (optional)
        if (!empty($sentimentAnalysis['themes'])) {
            $themeAdjustment = $this->calculateThemeAdjustment(
                $sentimentAnalysis['themes'],
                $sourceWeights
            );
            $adjustedScore += $themeAdjustment;
        }

        // Cap between 0-100
        $finalScore = max(0, min(100, (int)round($adjustedScore)));

        return [
            'reputation_score' => $finalScore,
            'calculation' => [
                'base' => 50,
                'sentiment_adjustment' => round($averageAdjustment, 2),
                'theme_adjustment' => $themeAdjustment ?? 0,
                'final' => $finalScore
            ]
        ];
    }

    /**
     * Get sentiment for a specific mention
     * 
     * @param array $mention
     * @param array $sentimentAnalysis
     * @return string
     */
    private function getMentionSentiment(array $mention, array $sentimentAnalysis): string
    {
        // Check if mention exists in top_mentions with sentiment
        foreach ($sentimentAnalysis['top_mentions'] ?? [] as $analyzed) {
            if ($analyzed['url'] === $mention['url']) {
                return $analyzed['sentiment'];
            }
        }

        // Default based on sentiment breakdown
        $breakdown = $sentimentAnalysis['sentiment_breakdown'] ?? [];
        if (($breakdown['positive'] ?? 0) > ($breakdown['negative'] ?? 0)) {
            return 'positive';
        } else if (($breakdown['negative'] ?? 0) > ($breakdown['positive'] ?? 0)) {
            return 'negative';
        }

        return 'neutral';
    }

    /**
     * Calculate theme-based adjustments
     * 
     * @param array $themes
     * @param array $sourceWeights
     * @return float
     */
    private function calculateThemeAdjustment(array $themes, array $sourceWeights): float
    {
        $adjustment = 0;

        foreach ($themes as $theme) {
            $frequency = $theme['frequency'] ?? 0;
            $sentiment = $theme['sentiment'] ?? 'neutral';

            // ±2 points per theme frequency
            $themeValue = match ($sentiment) {
                'positive' => 2,
                'negative' => -2,
                'neutral' => 0,
                default => 0
            };

            $adjustment += ($themeValue * $frequency) * 0.1; // Reduce theme impact
        }

        return $adjustment;
    }

    /**
     * Get score interpretation
     * 
     * @param int $score
     * @return string
     */
    public function getScoreInterpretation(int $score): string
    {
        return match (true) {
            $score >= 80 => 'Excellent',
            $score >= 60 => 'Good',
            $score >= 40 => 'Fair',
            $score >= 20 => 'Poor',
            default => 'Critical'
        };
    }
}
