<?php

namespace App\Domain\Community;

use App\Domain\Localization\TranslationWorkflow;
use App\Domain\Quality\QaScorer;
use App\Enums\TranslationStatus;
use App\Models\LocalizationPackage;
use App\Models\PackageVersion;
use App\Models\Translation;
use App\Models\TranslationFeedback;
use App\Models\User;
use App\Notifications\FeedbackResolved;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Community input into the existing review workflow:
 *  - reports: any owner of a package flags a line (usually from an app, by entry id);
 *  - suggestions: approved contributors propose better wording for lines of packages they own.
 * A reviewer resolves each one. Accepted wording is applied through TranslationWorkflow (→ Approved), so it ships with
 * the next published build exactly like staff edits — nothing reaches players without a reviewer.
 */
final class FeedbackService
{
    public function __construct(private TranslationWorkflow $workflow, private QaScorer $qa) {}

    /** Lines a package delivers: its game + style, its (effective) categories. */
    public static function linesOf(LocalizationPackage $package)
    {
        return Translation::query()
            ->where('game_id', $package->game_id)
            ->where('style_id', $package->style_id)
            ->whereIn('category_id', $package->effectiveCategories()->pluck('id'));
    }

    public function submit(User $user, LocalizationPackage $package, Translation $line, string $kind, array $data): TranslationFeedback
    {
        if (! in_array($kind, TranslationFeedback::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => __('community.bad_kind')]);
        }
        if (! $user->owns($package)) {
            throw ValidationException::withMessages(['package' => __('community.must_own')]);
        }
        if ($kind === 'suggestion' && ! $user->isContributor()) {
            throw ValidationException::withMessages(['kind' => __('community.contributors_only')]);
        }
        if (! self::linesOf($package)->whereKey($line->id)->exists()) {
            throw ValidationException::withMessages(['entry_id' => __('community.not_in_package')]);
        }
        $text = isset($data['suggested_text']) ? trim((string) $data['suggested_text']) : null;
        if ($kind === 'suggestion' && ($text === null || $text === '' || $text === trim((string) $line->target_text))) {
            throw ValidationException::withMessages(['suggested_text' => __('community.suggestion_required')]);
        }
        // One open item per person per line keeps the queue honest.
        if (TranslationFeedback::where('user_id', $user->id)->where('translation_id', $line->id)->where('status', 'open')->exists()) {
            throw ValidationException::withMessages(['entry_id' => __('community.already_open')]);
        }

        $version = isset($data['version'])
            ? PackageVersion::where('package_id', $package->id)->where('version', $data['version'])->first()
            : null;

        return TranslationFeedback::create([
            'translation_id' => $line->id,
            'package_id' => $package->id,
            'package_version_id' => $version?->id,
            'user_id' => $user->id,
            'kind' => $kind,
            'reason' => $data['reason'] ?? 'other',
            'suggested_text' => $text ?: null,
            'note' => isset($data['note']) ? trim((string) $data['note']) ?: null : null,
            'source' => in_array($data['source'] ?? 'store', TranslationFeedback::SOURCES, true) ? ($data['source'] ?? 'store') : 'store',
        ]);
    }

    /**
     * Accept: the problem is real. When $text differs from the current wording it is applied and the line moves to
     * Approved (reviewed by $reviewer), ready for the next publish.
     */
    public function accept(TranslationFeedback $fb, User $reviewer, ?string $text = null, ?string $note = null): TranslationFeedback
    {
        $this->guard($fb, $reviewer);
        DB::transaction(function () use ($fb, $reviewer, $text, $note) {
            $line = Translation::whereKey($fb->translation_id)->lockForUpdate()->firstOrFail();
            $text = $text !== null ? trim($text) : null;
            $applied = null;
            if ($text !== null && $text !== '' && $text !== trim((string) $line->target_text)) {
                $comment = __('community.revision_comment', ['id' => $fb->id, 'name' => $fb->user->publicName()]);
                $line->target_text = $text;
                // Walk the normal workflow (every state may go back to In review), recorded as revisions.
                if ($line->status !== TranslationStatus::InReview) {
                    $this->workflow->transition($line, TranslationStatus::InReview, $reviewer, $comment);
                } else {
                    $line->revisionComment = $comment;
                    $line->save();
                }
                $this->workflow->transition($line, TranslationStatus::Approved, $reviewer, $comment);
                $applied = $text;
            }
            $fb->forceFill([
                'status' => 'accepted', 'resolved_by' => $reviewer->id, 'resolved_at' => now(),
                'resolution_note' => $note, 'applied_text' => $applied,
            ])->save();
        });

        $this->after($fb);

        return $fb->fresh();
    }

    public function reject(TranslationFeedback $fb, User $reviewer, ?string $note = null): TranslationFeedback
    {
        return $this->close($fb, $reviewer, 'rejected', $note);
    }

    public function markDuplicate(TranslationFeedback $fb, User $reviewer, ?string $note = null): TranslationFeedback
    {
        return $this->close($fb, $reviewer, 'duplicate', $note);
    }

    private function close(TranslationFeedback $fb, User $reviewer, string $status, ?string $note): TranslationFeedback
    {
        $this->guard($fb, $reviewer);
        $fb->forceFill(['status' => $status, 'resolved_by' => $reviewer->id, 'resolved_at' => now(), 'resolution_note' => $note])->save();
        $this->after($fb);

        return $fb->fresh();
    }

    private function guard(TranslationFeedback $fb, User $reviewer): void
    {
        if (! $fb->isOpen()) {
            throw ValidationException::withMessages(['status' => __('community.already_resolved')]);
        }
        if (! $reviewer->is_staff || ! $reviewer->hasAnyRole(['super_admin', 'reviewer'])) {
            throw ValidationException::withMessages(['status' => __('community.reviewers_only')]);
        }
        // Four eyes: nobody resolves their own feedback.
        if ($fb->user_id === $reviewer->id) {
            throw ValidationException::withMessages(['status' => __('community.not_own')]);
        }
    }

    private function after(TranslationFeedback $fb): void
    {
        $fb->user->notify(new FeedbackResolved($fb->fresh(['translation', 'package'])));
        if ($fb->package) {
            $this->qa->refresh($fb->package);
        }
    }
}
