<?php

declare(strict_types=1);

namespace App\Domain\Matching;

use App\Data\Matching\MatchScoreData;
use App\Models\MatchScore;
use App\Models\PartnerPreference;
use App\Models\Profile;
use Carbon\CarbonImmutable;

/**
 * MatchScorer (PRD §10 M05, F06):
 * Computes compatibility score between a source and target profile:
 * - Preference Fit: 60%
 * - Reverse Fit: 25%
 * - Activity: 10%
 * - Completeness: 5%
 * Total: 0..100
 */
final class MatchScorer
{
    public function calculate(Profile $source, Profile $target): MatchScoreData
    {
        $source->loadMissing(['partnerPreference', 'district']);
        $target->loadMissing(['partnerPreference', 'district', 'educationCareer']);

        $prefFit = $this->calculatePreferenceFit($source->partnerPreference, $target);
        $revFit = $this->calculateReverseFit($target->partnerPreference, $source);
        $activity = $this->calculateActivityScore($target);
        $completeness = $this->calculateCompletenessScore($target);

        $total = min(100, max(0, $prefFit + $revFit + $activity + $completeness));

        return new MatchScoreData(
            totalScore: $total,
            preferenceFit: $prefFit,
            reverseFit: $revFit,
            activityScore: $activity,
            completenessScore: $completeness,
        );
    }

    public function scoreAndCache(Profile $source, Profile $target): MatchScore
    {
        $data = $this->calculate($source, $target);

        return MatchScore::query()->updateOrCreate(
            [
                'source_profile_id' => $source->id,
                'target_profile_id' => $target->id,
            ],
            [
                'score' => $data->totalScore,
                'preference_fit' => $data->preferenceFit,
                'reverse_fit' => $data->reverseFit,
                'activity_score' => $data->activityScore,
                'completeness_score' => $data->completenessScore,
                'calculated_at' => now(),
            ],
        );
    }

    /**
     * Preference Fit (0..60 pts).
     */
    private function calculatePreferenceFit(?PartnerPreference $pref, Profile $target): int
    {
        if ($pref === null) {
            return 35; // Neutral baseline when source has not filled preferences
        }

        $points = 0;

        // Age range (15 pts)
        $targetAge = $target->age();
        if ($targetAge !== null) {
            $from = $pref->age_min ?? 18;
            $to = $pref->age_max ?? 70;
            if ($targetAge >= $from && $targetAge <= $to) {
                $points += 15;
            } elseif ($targetAge >= $from - 2 && $targetAge <= $to + 2) {
                $points += 8;
            }
        } else {
            $points += 10;
        }

        // Height range (5 pts)
        $targetHeight = $target->height_cm;
        if ($targetHeight !== null) {
            $fromH = $pref->height_min_cm ?? 120;
            $toH = $pref->height_max_cm ?? 220;
            if ($targetHeight >= $fromH && $targetHeight <= $toH) {
                $points += 5;
            } elseif ($targetHeight >= $fromH - 5 && $targetHeight <= $toH + 5) {
                $points += 3;
            }
        } else {
            $points += 3;
        }

        // Religion (10 pts)
        if (! empty($pref->religion_ids)) {
            if ($target->religion_id !== null && in_array($target->religion_id, $pref->religion_ids, true)) {
                $points += 10;
            }
        } else {
            $points += 8;
        }

        // Caste (10 pts)
        if ($target->caste_no_bar) {
            $points += 10;
        } elseif (! empty($pref->caste_ids)) {
            if ($target->caste_id !== null && in_array($target->caste_id, $pref->caste_ids, true)) {
                $points += 10;
            }
        } else {
            $points += 8;
        }

        // Marital status (8 pts)
        if (! empty($pref->marital_statuses)) {
            if ($target->marital_status !== null && in_array($target->marital_status->value, $pref->marital_statuses, true)) {
                $points += 8;
            }
        } else {
            $points += 6;
        }

        // District / Location (7 pts)
        if (! empty($pref->district_ids)) {
            if ($target->district_id !== null && in_array($target->district_id, $pref->district_ids, true)) {
                $points += 7;
            }
        } else {
            $points += 5;
        }

        // Education / Occupation (5 pts)
        if (! empty($pref->education_ids) && $target->educationCareer?->education_id !== null) {
            if (in_array($target->educationCareer->education_id, $pref->education_ids, true)) {
                $points += 5;
            }
        } else {
            $points += 4;
        }

        return min(60, max(0, $points));
    }

    /**
     * Reverse Fit (0..25 pts): how well source matches target's preferences.
     */
    private function calculateReverseFit(?PartnerPreference $targetPref, Profile $source): int
    {
        if ($targetPref === null) {
            return 15; // Neutral baseline when target has no preferences
        }

        // Compute source's fit on target's preferences (0..60) and scale to 25 pts
        $rawFit = $this->calculatePreferenceFit($targetPref, $source);

        return (int) round(($rawFit / 60) * 25);
    }

    /**
     * Activity Score (0..10 pts).
     */
    private function calculateActivityScore(Profile $target): int
    {
        $lastActive = $target->last_active_at;

        if ($lastActive === null) {
            return 2;
        }

        $now = CarbonImmutable::now();

        if ($lastActive->gte($now->subHours(24))) {
            return 10;
        }

        if ($lastActive->gte($now->subDays(7))) {
            return 7;
        }

        if ($lastActive->gte($now->subDays(30))) {
            return 4;
        }

        return 2;
    }

    /**
     * Completeness Score (0..5 pts).
     */
    private function calculateCompletenessScore(Profile $target): int
    {
        $completeness = $target->completeness;

        if ($completeness >= 90) {
            return 5;
        }

        if ($completeness >= 75) {
            return 4;
        }

        if ($completeness >= 50) {
            return 3;
        }

        if ($completeness >= 25) {
            return 2;
        }

        return 1;
    }
}
