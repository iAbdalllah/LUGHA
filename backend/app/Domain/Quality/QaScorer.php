<?php

namespace App\Domain\Quality;

use App\Domain\Community\FeedbackService;
use App\Enums\TranslationStatus;
use App\Models\LocalizationPackage;
use App\Models\TranslationFeedback;

/**
 * Public QA score (0–100) for a package, from things LUGHA controls and can explain:
 *  - coverage (40%): share of the package's lines that are published (from the latest release when there is one);
 *  - review (30%): share of published lines approved by a reviewer other than their translator (four eyes);
 *  - field quality (30%): confirmed player reports in the last 90 days per 1,000 lines (10 points per report/1k).
 * Ratings are shown separately and never feed the score, so the score can't be review-bombed.
 */
final class QaScorer
{
    public const WEIGHTS = ['coverage' => 0.4, 'review' => 0.3, 'field' => 0.3];

    public const MIN_LINES = 20;

    public function compute(LocalizationPackage $package): ?array
    {
        $lines = FeedbackService::linesOf($package);
        $published = (clone $lines)->where('status', TranslationStatus::Published);
        $total = (clone $published)->count();
        if ($total < self::MIN_LINES) {
            return null;
        }

        $release = $package->latestRelease();
        $bp = $release?->coverage ? array_values((array) $release->coverage) : [];
        if ($bp) {
            $coverage = array_sum($bp) / count($bp) / 100;
        } else {
            $all = (clone $lines)->where('status', '!=', TranslationStatus::Rejected)->count();
            $coverage = $all ? 100 * $total / $all : 0;
        }

        $fourEyes = (clone $published)->whereNotNull('reviewer_id')
            ->where(fn ($q) => $q->whereNull('translator_id')->orWhereColumn('reviewer_id', '!=', 'translator_id'))->count();
        $review = 100 * $fourEyes / $total;

        $confirmed = TranslationFeedback::where('kind', 'report')->where('status', 'accepted')
            ->where('resolved_at', '>=', now()->subDays(90))
            ->whereIn('translation_id', (clone $lines)->select('id'))->count();
        $per1k = 1000 * $confirmed / $total;
        $field = max(0, 100 - 10 * $per1k);

        $parts = ['coverage' => round($coverage, 1), 'review' => round($review, 1), 'field' => round($field, 1)];
        $score = (int) round(collect(self::WEIGHTS)->map(fn ($w, $k) => $w * $parts[$k])->sum());

        return ['score' => max(0, min(100, $score)), 'parts' => $parts, 'lines' => $total, 'confirmed_reports_90d' => $confirmed];
    }

    public function refresh(LocalizationPackage $package): ?int
    {
        $r = $this->compute($package);
        $package->forceFill([
            'qa_score' => $r['score'] ?? null,
            'qa_breakdown' => $r,
            'qa_computed_at' => now(),
        ])->saveQuietly();

        return $r['score'] ?? null;
    }
}
