<?php

namespace Tests\Feature;

use App\Domain\Localization\TextNormalizer;
use App\Domain\Localization\TranslationTransfer;
use App\Domain\Localization\TranslationWorkflow;
use App\Enums\RightsStatus;
use App\Enums\TranslationStatus;
use App\Jobs\ImportTranslationsJob;
use App\Models\LocalizationStyle;
use App\Models\Translation;
use App\Models\TranslationCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

class TranslationWorkflowTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
    }

    private function line(array $attrs = []): Translation
    {
        return Translation::create($attrs + [
            'game_id' => $this->game()->id, 'category_id' => TranslationCategory::where('slug', 'dialogues')->value('id'),
            'style_id' => LocalizationStyle::first()->id, 'source_text' => 'We leave at dawn.', 'target_text' => 'نطلع مع الفجر.',
        ]);
    }

    public function test_review_workflow_and_revision_history(): void
    {
        $reviewer = $this->staff('reviewer');
        $this->actingAs($reviewer);
        $t = $this->line();
        $flow = app(TranslationWorkflow::class);

        $flow->transition($t, TranslationStatus::InReview, $reviewer);
        $flow->transition($t, TranslationStatus::Rejected, $reviewer, 'Use glossary term');
        $flow->transition($t, TranslationStatus::Draft, $reviewer);
        $flow->transition($t, TranslationStatus::InReview, $reviewer);
        $flow->transition($t, TranslationStatus::Approved, $reviewer);
        $flow->transition($t, TranslationStatus::Published, $reviewer);

        $this->assertSame(TranslationStatus::Published, $t->fresh()->status);
        $this->assertSame(7, $t->revisions()->count());
        $this->assertSame('Use glossary term', $t->revisions()->where('status', 'rejected')->value('comment'));
        $this->assertSame($reviewer->id, $t->fresh()->reviewer_id);
    }

    public function test_cannot_skip_review_or_publish_empty_lines(): void
    {
        $user = $this->staff('translator');
        $t = $this->line(['target_text' => null]);

        $this->expectException(ValidationException::class);
        app(TranslationWorkflow::class)->transition($t, TranslationStatus::Published, $user);
    }

    public function test_empty_target_cannot_go_to_review(): void
    {
        $t = $this->line(['target_text' => '  ']);
        $this->expectException(ValidationException::class);
        app(TranslationWorkflow::class)->transition($t, TranslationStatus::InReview, $this->staff('translator'));
    }

    public function test_source_hash_matches_normalizer(): void
    {
        $t = $this->line(['source_text' => '  We   LEAVE at dawn!']);
        $this->assertSame(TextNormalizer::hash('we leave at dawn'), $t->source_hash);
    }

    public function test_csv_json_and_xlsx_round_trip(): void
    {
        $this->actingAs($this->staff('translator'));
        $game = $this->game();
        $style = LocalizationStyle::first();
        $transfer = app(TranslationTransfer::class);

        $csv = tempnam(sys_get_temp_dir(), 'lg').'.csv';
        file_put_contents($csv, "string_key,category,source_text,target_text,speaker,status\n"
            ."K1,dialogues,Hello there,هلا والله,Rim,in_review\n"
            ."K2,menus,Continue,متابعة,,published\n"
            .",nope,Bad row,,,\n"
            ."K3,dialogues,,x,,\n");
        $stats = $transfer->import($csv, 'csv', $game, $style);

        $this->assertSame(2, $stats['created']);
        $this->assertCount(2, $stats['errors']);
        $this->assertSame(TranslationStatus::InReview, Translation::where('string_key', 'K2')->first()->status, 'imports never publish');

        foreach (['xlsx', 'json', 'csv'] as $format) {
            $path = $transfer->export($game, $format);
            $this->assertFileExists($path);
            $again = $transfer->import($path, $format, $game, $style);
            $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 2], array_diff_key($again, ['errors' => 0, 'duplicates' => 0]), $format);
        }
    }

    public function test_a_large_file_imports_in_the_background_and_notifies_the_uploader(): void
    {
        $user = $this->staff('translator');
        $game = $this->game();
        $style = LocalizationStyle::first();
        $rows = TranslationTransfer::CHUNK * 2 + 7; // spans several chunks
        $csv = "string_key,category,source_text,target_text\n";
        for ($i = 1; $i <= $rows; $i++) {
            $csv .= "BIG_$i,dialogues,Line number $i,سطر $i\n";
        }
        $csv .= "BIG_1,dialogues,Line number 1,سطر واحد\n"; // the same key again later in the file updates, not duplicates
        $csv .= "OTHER_KEY,dialogues,Line number 2,سطر ٢\n"; // another key with the same English shares that line
        $csv .= ",dialogues,Line number 3,سطر ٣\n"; // an unkeyed row with known English updates it
        $csv .= "SING_1,dialogue,Singular category,مفرد\nSUB_1,Subtitle,A subtitle,ترجمة\n"; // other tools' category names
        Storage::disk('local')->put('imports/big.csv', $csv);

        ImportTranslationsJob::dispatchSync('imports/big.csv', $game, $style, $user);

        $this->assertSame($rows + 2, Translation::where('game_id', $game->id)->count());
        $this->assertSame('dialogues', Translation::where('string_key', 'SUB_1')->first()->category->slug);
        $this->assertSame('سطر واحد', Translation::where('string_key', 'BIG_1')->value('target_text'));
        $this->assertSame('سطر ٣', Translation::where('string_key', 'BIG_3')->value('target_text'));
        $this->assertNull(Translation::where('string_key', 'OTHER_KEY')->first());
        $this->assertSame($user->id, Translation::where('string_key', 'BIG_9')->value('translator_id'));
        Storage::disk('local')->assertMissing('imports/big.csv');
        $note = $user->notifications()->first();
        $this->assertNotNull($note, 'the uploader is told when it is done');
        $this->assertStringContainsString((string) ($rows + 2), json_encode($note->data, JSON_UNESCAPED_UNICODE));
    }

    public function test_uploads_allow_whole_game_files(): void
    {
        $this->assertContains('max:204800', config('livewire.temporary_file_upload.rules'));
        $this->assertSame(204800, config('lugha.max_upload_kb'));
    }

    public function test_import_that_changes_published_text_sends_it_back_to_review(): void
    {
        $this->actingAs($this->staff('translator'));
        $t = $this->line(['string_key' => 'K1', 'status' => TranslationStatus::Published]);
        $json = tempnam(sys_get_temp_dir(), 'lg').'.json';
        file_put_contents($json, json_encode([['string_key' => 'K1', 'category' => 'dialogues', 'source_text' => 'We leave at dawn.', 'target_text' => 'نمشي الفجر.']]));

        app(TranslationTransfer::class)->import($json, 'json', $t->game, $t->style);

        $this->assertSame(TranslationStatus::InReview, $t->fresh()->status);
        $this->assertSame('نمشي الفجر.', $t->fresh()->target_text);
    }

    public function test_packages_cannot_be_published_without_cleared_rights(): void
    {
        $game = $this->game(['rights_status' => RightsStatus::Pending]);
        $this->expectException(ValidationException::class);
        $this->package($game, 3900);
    }
}
