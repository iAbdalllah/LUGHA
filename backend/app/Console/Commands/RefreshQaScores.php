<?php

namespace App\Console\Commands;

use App\Domain\Quality\QaScorer;
use App\Models\LocalizationPackage;
use Illuminate\Console\Command;

class RefreshQaScores extends Command
{
    protected $signature = 'lugha:qa-scores';

    protected $description = 'Recompute the public QA score of every package (coverage, four-eyes review, confirmed reports)';

    public function handle(QaScorer $qa): int
    {
        LocalizationPackage::query()->each(function (LocalizationPackage $p) use ($qa) {
            $score = $qa->refresh($p);
            $this->line(sprintf('%-32s %s', $p->slug, $score ?? '—'));
        });

        return self::SUCCESS;
    }
}
