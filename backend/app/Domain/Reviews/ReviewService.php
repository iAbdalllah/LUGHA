<?php

namespace App\Domain\Reviews;

use App\Models\LocalizationPackage;
use App\Models\PackageReview;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Verified-owner reviews: one per player per package, editable; staff can hide (never edit) a review. */
final class ReviewService
{
    public function save(User $user, LocalizationPackage $package, array $data): PackageReview
    {
        if (! $user->owns($package)) {
            throw ValidationException::withMessages(['rating' => __('reviews.must_own')]);
        }
        $review = PackageReview::firstOrNew(['package_id' => $package->id, 'user_id' => $user->id]);
        if ($review->exists && $review->status === 'hidden') {
            // A hidden review stays hidden until staff restore it; the player may still edit the text.
            $review->fill(['rating' => $data['rating'], 'title' => $data['title'] ?? null, 'body' => $data['body'] ?? null]);
        } else {
            $review->fill([
                'rating' => $data['rating'], 'title' => $data['title'] ?? null, 'body' => $data['body'] ?? null,
                'locale' => $data['locale'] ?? app()->getLocale(), 'status' => 'published',
            ]);
        }
        $review->package_version = $package->latestRelease()?->version;
        $review->save();
        $this->refresh($package);

        return $review;
    }

    public function delete(User $user, LocalizationPackage $package): void
    {
        PackageReview::where('package_id', $package->id)->where('user_id', $user->id)->delete();
        $this->refresh($package);
    }

    public function hide(PackageReview $review, User $moderator, string $reason): void
    {
        $review->forceFill(['status' => 'hidden', 'hidden_reason' => $reason, 'moderated_by' => $moderator->id])->save();
        $this->refresh($review->package);
    }

    public function restore(PackageReview $review, User $moderator): void
    {
        $review->forceFill(['status' => 'published', 'hidden_reason' => null, 'moderated_by' => $moderator->id])->save();
        $this->refresh($review->package);
    }

    public function refresh(LocalizationPackage $package): void
    {
        $q = PackageReview::where('package_id', $package->id)->visible();
        $count = (clone $q)->count();
        $package->forceFill([
            'rating_count' => $count,
            'rating_avg' => $count ? round((float) (clone $q)->avg('rating'), 2) : null,
        ])->saveQuietly();
    }

    /** @return array<int,int> stars => count, 5..1 */
    public static function distribution(LocalizationPackage $package): array
    {
        $counts = PackageReview::where('package_id', $package->id)->visible()
            ->selectRaw('rating, count(*) as n')->groupBy('rating')->pluck('n', 'rating');

        return collect([5, 4, 3, 2, 1])->mapWithKeys(fn ($r) => [$r => (int) ($counts[$r] ?? 0)])->all();
    }
}
