<?php

namespace Tests\Feature;

use App\Domain\Analytics\Metrics;
use App\Domain\Community\FeedbackService;
use App\Domain\Entitlements\EntitlementService;
use App\Domain\Quality\QaScorer;
use App\Domain\Reviews\ReviewService;
use App\Enums\EntitlementSource;
use App\Enums\TranslationStatus;
use App\Models\AnalyticsEvent;
use App\Models\LocalizationPackage;
use App\Models\LocalizationStyle;
use App\Models\PackageReview;
use App\Models\Translation;
use App\Models\TranslationCategory;
use App\Models\TranslationFeedback;
use App\Models\User;
use App\Notifications\FeedbackResolved;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

/** Phase 5: community feedback → review workflow, reviews, QA scores, analytics. */
class GrowthTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    private LocalizationPackage $pkg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        $this->pkg = $this->package($this->game(), 4900);
    }

    /** @return Translation[] */
    private function lines(int $n, array $attrs = [], string $prefix = 'Line'): array
    {
        $cat = TranslationCategory::where('slug', 'dialogues')->value('id');

        return collect(range(1, $n))->map(fn ($i) => Translation::create($attrs + [
            'game_id' => $this->pkg->game_id, 'category_id' => $cat, 'style_id' => $this->pkg->style_id,
            'source_text' => "$prefix number $i.", 'target_text' => "السطر رقم $i.", 'status' => TranslationStatus::Published,
        ]))->all();
    }

    private function owner(array $attrs = []): User
    {
        $u = $this->customer($attrs);
        app(EntitlementService::class)->grant($u, $this->pkg, EntitlementSource::Purchase);

        return $u;
    }

    private function contributor(): User
    {
        $u = $this->owner();
        $u->forceFill(['contributor_status' => 'approved', 'contributor_display_name' => 'Noura'])->save();

        return $u;
    }

    // ---- community ----

    public function test_owner_reports_a_line_from_an_app_by_entry_id(): void
    {
        [$line] = $this->lines(1);
        $owner = $this->owner();
        $token = $owner->createToken('mac', ['*'])->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/translation-reports', [
            'package' => $this->pkg->slug, 'entry_id' => $line->id, 'reason' => 'typo', 'note' => 'Missing hamza', 'source' => 'macos',
        ])->assertStatus(202)->assertJsonPath('status', 'open');

        $fb = TranslationFeedback::sole();
        $this->assertSame(['report', 'macos', $line->id], [$fb->kind, $fb->source, $fb->translation_id]);

        // A second open report for the same line is refused; a stranger cannot report at all.
        $this->withToken($token)->postJson('/api/v1/translation-reports', ['package' => $this->pkg->slug, 'entry_id' => $line->id, 'reason' => 'typo'])
            ->assertStatus(422);
        $stranger = $this->customer()->createToken('x', ['*'])->plainTextToken;
        $this->withToken($stranger)->postJson('/api/v1/translation-reports', ['package' => $this->pkg->slug, 'entry_id' => $line->id, 'reason' => 'typo'])
            ->assertStatus(422);
    }

    public function test_lines_outside_the_package_cannot_be_reported(): void
    {
        $other = Translation::create([
            'game_id' => $this->game()->id, 'category_id' => TranslationCategory::where('slug', 'dialogues')->value('id'),
            'style_id' => LocalizationStyle::first()->id, 'source_text' => 'Other game.', 'target_text' => 'لعبة أخرى.', 'status' => TranslationStatus::Published,
        ]);
        $this->expectException(ValidationException::class);
        app(FeedbackService::class)->submit($this->owner(), $this->pkg, $other, 'report', ['reason' => 'typo']);
    }

    public function test_suggestions_are_for_approved_contributors_only(): void
    {
        [$line] = $this->lines(1);
        $owner = $this->owner();
        $this->actingAs($owner)->get(route('contribute.lines', ['locale' => 'ar', 'package' => $this->pkg]))->assertForbidden();

        $this->actingAs($owner)->post(route('contribute.apply', ['locale' => 'ar']), ['display_name' => 'Sara', 'accept_terms' => '1'])->assertRedirect();
        $this->assertSame('applied', $owner->fresh()->contributor_status);
        $this->actingAs($owner)->get(route('contribute.lines', ['locale' => 'ar', 'package' => $this->pkg]))->assertForbidden();

        $owner->forceFill(['contributor_status' => 'approved'])->save();
        $this->actingAs($owner)->get(route('contribute.lines', ['locale' => 'ar', 'package' => $this->pkg]))
            ->assertOk()->assertSee($line->source_text)->assertSee($line->target_text);
        $this->actingAs($owner)->post(route('contribute.suggest', ['locale' => 'ar', 'package' => $this->pkg]), [
            'entry_id' => $line->id, 'reason' => 'awkward', 'suggested_text' => 'هذا السطر الأول.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('suggestion', TranslationFeedback::sole()->kind);
    }

    public function test_reviewer_accepts_a_suggestion_through_the_translation_workflow(): void
    {
        Notification::fake();
        [$line] = $this->lines(1);
        $contributor = $this->contributor();
        $fb = app(FeedbackService::class)->submit($contributor, $this->pkg, $line, 'suggestion', ['reason' => 'awkward', 'suggested_text' => 'هذا هو السطر الأول.']);

        $reviewer = $this->staff('reviewer');
        $this->actingAs($reviewer);
        app(FeedbackService::class)->accept($fb, $reviewer, 'هذا هو السطر الأول.', 'Nice');

        $line->refresh();
        $this->assertSame('هذا هو السطر الأول.', $line->target_text);
        $this->assertSame(TranslationStatus::Approved, $line->status, 'accepted wording waits for the normal publish step');
        $this->assertSame($reviewer->id, $line->reviewer_id);
        $this->assertStringContainsString('Noura', $line->revisions()->latest('revision')->value('comment'));
        $this->assertSame(['accepted', 'هذا هو السطر الأول.'], [$fb->fresh()->status, $fb->fresh()->applied_text]);
        Notification::assertSentTo($contributor, FeedbackResolved::class);
    }

    public function test_accepting_a_report_without_new_wording_only_confirms_it(): void
    {
        [$line] = $this->lines(1);
        $fb = app(FeedbackService::class)->submit($this->owner(), $this->pkg, $line, 'report', ['reason' => 'typo']);
        $reviewer = $this->staff('reviewer');
        app(FeedbackService::class)->accept($fb, $reviewer, $line->target_text);

        $this->assertSame(TranslationStatus::Published, $line->fresh()->status);
        $this->assertNull($fb->fresh()->applied_text);
        $this->assertSame('accepted', $fb->fresh()->status);
    }

    public function test_only_reviewers_resolve_and_never_their_own_feedback(): void
    {
        [$a, $b] = $this->lines(2);
        $svc = app(FeedbackService::class);

        $fb = $svc->submit($this->owner(), $this->pkg, $a, 'report', ['reason' => 'typo']);
        try {
            $svc->reject($fb, $this->staff('translator'));
            $this->fail('translator resolved feedback');
        } catch (ValidationException) {
        }

        $reviewer = $this->staff('reviewer');
        app(EntitlementService::class)->grant($reviewer, $this->pkg, EntitlementSource::Admin);
        $own = $svc->submit($reviewer, $this->pkg, $b, 'report', ['reason' => 'typo']);
        $this->expectException(ValidationException::class);
        $svc->reject($own, $reviewer);
    }

    public function test_admin_queue_lists_open_feedback_for_reviewers(): void
    {
        [$line] = $this->lines(1);
        app(FeedbackService::class)->submit($this->owner(), $this->pkg, $line, 'report', ['reason' => 'typo']);
        $this->actingAs($this->staff('reviewer'))->get('/admin/community-feedback')->assertOk()->assertSee($line->source_text);
        $this->flushSession();
        $this->actingAs($this->staff('finance'))->get('/admin/community-feedback')->assertForbidden();
    }

    // ---- reviews ----

    public function test_only_owners_review_and_aggregates_follow_moderation(): void
    {
        $this->actingAs($this->customer())->post(route('reviews.store', ['locale' => 'ar', 'package' => $this->pkg]), ['rating' => 5])
            ->assertSessionHasErrors('rating');

        $a = $this->owner();
        $b = $this->owner();
        $this->actingAs($a)->post(route('reviews.store', ['locale' => 'ar', 'package' => $this->pkg]), ['rating' => 5, 'title' => 'ممتاز', 'body' => 'ترجمة طبيعية'])->assertRedirect();
        $this->actingAs($b)->post(route('reviews.store', ['locale' => 'ar', 'package' => $this->pkg]), ['rating' => 2])->assertRedirect();
        // Editing keeps one review per player.
        $this->actingAs($b)->post(route('reviews.store', ['locale' => 'ar', 'package' => $this->pkg]), ['rating' => 3])->assertRedirect();

        $this->pkg->refresh();
        $this->assertSame([2, 4.0], [$this->pkg->rating_count, $this->pkg->rating_avg]);

        app(ReviewService::class)->hide(PackageReview::where('user_id', $b->id)->sole(), $this->staff('support'), 'spam');
        $this->pkg->refresh();
        $this->assertSame([1, 5.0], [$this->pkg->rating_count, $this->pkg->rating_avg]);

        $this->get(route('packages.show', ['locale' => 'ar', 'package' => $this->pkg]))->assertOk()->assertSee('ترجمة طبيعية')->assertSee('مراجعات اللاعبين');
        $this->getJson('/api/v1/packages/'.$this->pkg->slug.'/reviews')->assertOk()
            ->assertJsonPath('summary.count', 1)->assertJsonPath('summary.distribution.5', 1)->assertJsonCount(1, 'data');
    }

    // ---- QA score ----

    public function test_qa_score_combines_coverage_four_eyes_review_and_confirmed_reports(): void
    {
        $translator = $this->staff('translator');
        $reviewer = $this->staff('reviewer');
        $this->lines(30, ['translator_id' => $translator->id, 'reviewer_id' => $reviewer->id]);  // four eyes
        $this->lines(10, ['translator_id' => $translator->id, 'reviewer_id' => $translator->id], 'Self'); // self-reviewed
        Translation::create([
            'game_id' => $this->pkg->game_id, 'category_id' => TranslationCategory::where('slug', 'dialogues')->value('id'),
            'style_id' => $this->pkg->style_id, 'source_text' => 'Untranslated.', 'status' => TranslationStatus::Draft,
        ]);

        $r = app(QaScorer::class)->compute($this->pkg);
        $this->assertSame(40, $r['lines']);
        $this->assertEqualsWithDelta(97.6, $r['parts']['coverage'], 0.1); // 40 of 41 lines published
        $this->assertSame(75.0, $r['parts']['review']);
        $this->assertSame(100.0, $r['parts']['field']);
        $this->assertSame((int) round(0.4 * 97.56 + 0.3 * 75 + 0.3 * 100), $r['score']);

        // One confirmed report on 40 lines = 25 per 1,000 → field quality 0.
        $line = Translation::where('status', 'published')->first();
        $fb = app(FeedbackService::class)->submit($this->owner(), $this->pkg, $line, 'report', ['reason' => 'typo']);
        app(FeedbackService::class)->accept($fb, $reviewer);
        $this->assertEquals(0, $this->pkg->fresh()->qa_breakdown['parts']['field']);
        $this->assertNotNull($this->pkg->fresh()->qa_score);

        $this->get(route('packages.show', ['locale' => 'ar', 'package' => $this->pkg]))->assertSee('مؤشر الجودة');
    }

    public function test_small_packages_get_no_qa_score(): void
    {
        $this->lines(5);
        $this->assertNull(app(QaScorer::class)->refresh($this->pkg));
    }

    // ---- analytics ----

    public function test_package_views_are_tracked_without_personal_data_and_respect_opt_outs(): void
    {
        $url = route('packages.show', ['locale' => 'ar', 'package' => $this->pkg]);
        $this->get($url)->assertOk();
        $this->get($url, ['DNT' => '1'])->assertOk();
        $this->get($url, ['Sec-GPC' => '1'])->assertOk();
        $this->get($url, ['User-Agent' => 'Googlebot/2.1'])->assertOk();
        $this->actingAs($this->staff())->get($url)->assertOk();

        $e = AnalyticsEvent::sole();
        $this->assertSame(['package_view', $this->pkg->id], [$e->name, $e->package_id]);
        $this->assertSame(16, strlen($e->visitor));
        $this->assertArrayNotHasKey('ip', $e->getAttributes());
    }

    public function test_metrics_and_analytics_page(): void
    {
        $buyer = $this->customer();
        $this->actingAs($buyer)->get(route('packages.show', ['locale' => 'ar', 'package' => $this->pkg]));
        $this->actingAs($buyer)->post(route('cart.add', ['locale' => 'ar']), ['package' => $this->pkg->slug]);

        $m = new Metrics(30);
        $this->assertSame(1, $m->funnel()['package_view']);
        $this->assertSame(1, $m->funnel()['cart_add']);
        $this->assertSame(1, $m->packages()->firstWhere('views', 1)['cart_adds']);
        $this->assertCount(30, $m->revenueSeries()['labels']);

        $this->flushSession();
        $this->actingAs($this->staff('finance'))->get('/admin/analytics')->assertOk()->assertSee(__('Package performance'));
        $this->flushSession();
        $this->actingAs($this->staff('support'))->get('/admin/analytics')->assertForbidden();
    }
}
