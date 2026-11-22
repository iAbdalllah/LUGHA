<?php

namespace App\Domain\Localization;

use App\Enums\TranslationStatus;
use App\Models\Game;
use App\Models\LocalizationStyle;
use App\Models\Translation;
use App\Models\TranslationCategory;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Bulk import/export of translation lines (CSV, JSON, XLSX).
 * Imports never publish: new lines land as draft/in_review, and an edited approved/published line returns to review.
 */
final class TranslationTransfer
{
    public const COLUMNS = ['string_key', 'category', 'source_text', 'target_text', 'speaker', 'context', 'scene_ref', 'sequence_no', 'status'];

    /** @return array{created:int, updated:int, unchanged:int, errors:array<int,string>} */
    public function import(string $path, string $format, Game $game, LocalizationStyle $style, string $locale = 'ar-SA'): array
    {
        $rows = match ($format) {
            'json' => $this->readJson($path),
            'csv', 'xlsx' => $this->readSheet($path, $format),
            default => throw new \InvalidArgumentException("Unsupported format [$format]"),
        };

        $categories = TranslationCategory::pluck('id', 'slug');
        $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => []];

        DB::transaction(function () use ($rows, $categories, $game, $style, $locale, &$stats) {
            foreach ($rows as $i => $row) {
                $line = $i + 2; // header is line 1
                $source = trim((string) ($row['source_text'] ?? ''));
                $catId = $categories[trim((string) ($row['category'] ?? ''))] ?? null;
                if ($source === '') {
                    $stats['errors'][$line] = __('Missing source_text');

                    continue;
                }
                if (! $catId) {
                    $stats['errors'][$line] = __('Unknown category ":c"', ['c' => $row['category'] ?? '']);

                    continue;
                }
                $status = TranslationStatus::tryFrom((string) ($row['status'] ?? '')) ?? TranslationStatus::Draft;
                if (! in_array($status, [TranslationStatus::Draft, TranslationStatus::InReview], true)) {
                    $status = TranslationStatus::InReview;
                }

                $key = trim((string) ($row['string_key'] ?? '')) ?: null;
                $existing = Translation::query()->where('game_id', $game->id)->where('style_id', $style->id)->where('locale', $locale)
                    ->when($key, fn ($q) => $q->where('string_key', $key),
                        fn ($q) => $q->where('source_hash', TextNormalizer::hash($source))->where('category_id', $catId))
                    ->first();

                $attrs = [
                    'string_key' => $key, 'category_id' => $catId, 'source_text' => $source,
                    'target_text' => ($t = trim((string) ($row['target_text'] ?? ''))) === '' ? null : $t,
                    'speaker' => ($row['speaker'] ?? null) ?: null, 'context' => ($row['context'] ?? null) ?: null,
                    'scene_ref' => ($row['scene_ref'] ?? null) ?: null,
                    'sequence_no' => is_numeric($row['sequence_no'] ?? null) ? (int) $row['sequence_no'] : null,
                ];

                if (! $existing) {
                    Translation::create($attrs + ['game_id' => $game->id, 'style_id' => $style->id, 'locale' => $locale, 'status' => $status, 'translator_id' => auth()->id()]);
                    $stats['created']++;

                    continue;
                }

                $existing->fill($attrs);
                if (! $existing->isDirty()) {
                    $stats['unchanged']++;

                    continue;
                }
                if ($existing->isDirty(['target_text', 'source_text']) && in_array($existing->status, [TranslationStatus::Approved, TranslationStatus::Published], true)) {
                    $existing->status = TranslationStatus::InReview;
                    $existing->revisionComment = __('Changed by import');
                }
                $existing->save();
                $stats['updated']++;
            }
        });

        return $stats;
    }

    /** Writes the game's lines to a temp file and returns its path. */
    public function export(Game $game, string $format, ?array $statuses = null): string
    {
        $path = storage_path('app/private/exports/'.$game->slug.'-translations-'.now()->format('Ymd-His').'.'.$format);
        @mkdir(dirname($path), 0775, true);

        $query = Translation::query()->with('category')->where('game_id', $game->id)
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))->orderBy('scene_ref')->orderBy('sequence_no')->orderBy('id');

        $toRow = fn (Translation $t) => [
            'string_key' => $t->string_key, 'category' => $t->category->slug, 'source_text' => $t->source_text,
            'target_text' => $t->target_text, 'speaker' => $t->speaker, 'context' => $t->context, 'scene_ref' => $t->scene_ref,
            'sequence_no' => $t->sequence_no, 'status' => $t->status->value,
        ];

        if ($format === 'json') {
            $out = fopen($path, 'w');
            fwrite($out, "[\n");
            $first = true;
            foreach ($query->lazy() as $t) {
                fwrite($out, ($first ? '' : ",\n").json_encode($toRow($t), JSON_UNESCAPED_UNICODE));
                $first = false;
            }
            fwrite($out, "\n]\n");
            fclose($out);

            return $path;
        }

        $writer = $format === 'xlsx' ? new XlsxWriter : new CsvWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(self::COLUMNS));
        foreach ($query->lazy() as $t) {
            $writer->addRow(Row::fromValues(array_values($toRow($t))));
        }
        $writer->close();

        return $path;
    }

    private function readJson(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            throw new \InvalidArgumentException(__('The file is not a JSON array of lines.'));
        }

        return array_values(array_filter($data, 'is_array'));
    }

    private function readSheet(string $path, string $format): array
    {
        $reader = $format === 'xlsx' ? new XlsxReader : new CsvReader;
        $reader->open($path);
        $rows = [];
        $header = null;
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $values = array_map(fn ($v) => is_string($v) ? trim(preg_replace('/^\xEF\xBB\xBF/', '', $v)) : $v, $row->toArray());
                if ($header === null) {
                    $header = array_map('strtolower', $values);

                    continue;
                }
                if (array_filter($values, fn ($v) => $v !== null && $v !== '') === []) {
                    continue;
                }
                $rows[] = array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), null));
            }
            break; // first sheet only
        }
        $reader->close();

        return $rows;
    }
}
