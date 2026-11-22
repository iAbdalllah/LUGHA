<?php

namespace App\Domain\Localization;

use App\Enums\TranslationStatus;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class TranslationWorkflow
{
    public function transition(Translation $t, TranslationStatus $to, User $by, ?string $comment = null): Translation
    {
        if (! $t->status->canMoveTo($to)) {
            throw ValidationException::withMessages(['status' => __('translation.bad_transition', ['from' => $t->status->label(), 'to' => $to->label()])]);
        }
        if (in_array($to, [TranslationStatus::InReview, TranslationStatus::Approved, TranslationStatus::Published], true)
            && trim((string) $t->target_text) === '') {
            throw ValidationException::withMessages(['target_text' => __('translation.empty_target')]);
        }

        $t->status = $to;
        if (in_array($to, [TranslationStatus::Approved, TranslationStatus::Rejected], true)) {
            $t->reviewer_id = $by->id;
            $t->reviewed_at = now();
        }
        $t->revisionComment = $comment;
        $t->save();

        return $t;
    }
}
