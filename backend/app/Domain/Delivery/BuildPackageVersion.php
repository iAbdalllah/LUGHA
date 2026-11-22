<?php

namespace App\Domain\Delivery;

use App\Enums\TranslationStatus;
use App\Models\PackageFile;
use App\Models\PackageVersion;
use App\Models\Translation;
use App\Models\TranslationGlossary;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Turns the published translations of a package's categories into a signed, encrypted .lpk (and a delta
 * from the previous release of the same major) by running lugha-pkg. Content keys go to the tool through
 * the environment, never argv.
 */
final class BuildPackageVersion
{
    public const DISK = 'local';

    public function handle(PackageVersion $version): PackageVersion
    {
        if (in_array($version->build_status, ['released', 'revoked'], true)) {
            throw new RuntimeException('Released versions are immutable; create a new version.');
        }
        $version->update(['build_status' => 'building', 'build_log' => null]);
        $work = storage_path('app/private/builds/'.$version->id.'-'.bin2hex(random_bytes(4)));
        @mkdir($work, 0700, true);

        try {
            [$meta, $entries] = $this->export($version);
            file_put_contents("$work/meta.json", json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            file_put_contents("$work/entries.jsonl", implode("\n", array_map(fn ($e) => json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $entries)));

            $key = SigningKey::load();
            $contentKey = random_bytes(32);
            $result = $this->run(['build', '--meta', "$work/meta.json", '--entries', "$work/entries.jsonl", '--key', SigningKey::path(), '--out', "$work/full.lpk"],
                ['LUGHA_CONTENT_KEY' => bin2hex($contentKey)]);

            if ($result['code'] === 2) {
                $version->update(['build_status' => 'failed', 'build_report' => $result['json']['report'] ?? null,
                    'build_log' => 'Validation failed: '.count($result['json']['report']['errors'] ?? []).' error(s).']);

                return $version;
            }

            $package = $version->package;
            $dir = "packages/{$package->slug}";
            $fullPath = "$dir/{$package->slug}-{$version->version}.lpk";
            Storage::disk(self::DISK)->put($fullPath, file_get_contents("$work/full.lpk"));
            $version->files()->delete();
            PackageFile::create(['package_version_id' => $version->id, 'kind' => 'full', 'disk' => self::DISK, 'path' => $fullPath,
                'size_bytes' => $result['json']['file']['size'], 'sha256' => $result['json']['file']['sha256']]);

            $log = ["Full package: {$result['json']['file']['size']} bytes, ".count($entries).' lines.'];
            if ($base = $this->deltaBase($version)) {
                $baseFile = $base->fullFile();
                $delta = $this->run(['delta', '--base', Storage::disk($baseFile->disk)->path($baseFile->path), '--meta', "$work/meta.json",
                    '--entries', "$work/entries.jsonl", '--key', SigningKey::path(), '--out', "$work/delta.lpk"],
                    ['LUGHA_CONTENT_KEY' => bin2hex($contentKey), 'LUGHA_BASE_CONTENT_KEY' => bin2hex($base->contentKey())]);
                if ($delta['code'] === 0) {
                    $deltaPath = "$dir/{$package->slug}-{$base->version}_to_{$version->version}.lpkd";
                    Storage::disk(self::DISK)->put($deltaPath, file_get_contents("$work/delta.lpk"));
                    PackageFile::create(['package_version_id' => $version->id, 'kind' => 'delta', 'base_version_id' => $base->id, 'disk' => self::DISK,
                        'path' => $deltaPath, 'size_bytes' => $delta['json']['file']['size'], 'sha256' => $delta['json']['file']['sha256']]);
                    $log[] = "Delta from {$base->version}: {$delta['json']['file']['size']} bytes.";
                } else {
                    $log[] = "Delta from {$base->version} skipped: ".trim($delta['stderr']);
                }
            }

            $version->update([
                'build_status' => 'validated',
                'content_key_encrypted' => bin2hex($contentKey),
                'signing_key_id' => $key->keyId,
                'entry_count' => count($entries),
                'coverage' => $meta['coverage_bp'],
                'build_report' => $result['json']['report'] ?? null,
                'build_log' => implode("\n", $log),
            ]);

            return $version;
        } catch (\Throwable $e) {
            $version->update(['build_status' => 'failed', 'build_log' => $e->getMessage()]);
            throw $e;
        } finally {
            foreach (glob("$work/*") ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($work);
        }
    }

    /** @return array{0: array, 1: array<int, array>} */
    public function export(PackageVersion $version): array
    {
        $package = $version->package()->with(['game.versions', 'style', 'platforms'])->first();
        $categories = $package->effectiveCategories();
        $catIds = $categories->pluck('id');
        $slugs = $categories->pluck('slug', 'id');
        $minId = $version->game_version_min_id;
        $maxId = $version->game_version_max_id;

        $base = fn () => Translation::query()->where('game_id', $package->game_id)->where('style_id', $package->style_id)
            ->where('locale', $package->locale)->whereIn('category_id', $catIds)
            // Lines valid for at least part of this version's game-version range (ids grow with release order).
            ->when($maxId, fn ($q) => $q->where(fn ($w) => $w->whereNull('min_game_version_id')->orWhere('min_game_version_id', '<=', $maxId)))
            ->when($minId, fn ($q) => $q->where(fn ($w) => $w->whereNull('max_game_version_id')->orWhere('max_game_version_id', '>=', $minId)));

        $entries = $base()->where('status', TranslationStatus::Published)->orderBy('id')->get()
            ->map(fn (Translation $t) => array_filter([
                'id' => $t->id, 'source' => $t->source_text, 'target' => $t->target_text, 'category' => $slugs[$t->category_id],
                'speaker' => $t->speaker, 'scene' => $t->scene_ref, 'seq' => $t->sequence_no,
            ], fn ($v) => $v !== null))->values()->all();

        $totals = $base()->selectRaw('category_id, count(*) as n')->groupBy('category_id')->pluck('n', 'category_id');
        $published = $base()->where('status', TranslationStatus::Published)->selectRaw('category_id, count(*) as n')->groupBy('category_id')->pluck('n', 'category_id');
        $coverage = [];
        foreach ($catIds as $id) {
            if ($totals[$id] ?? 0) {
                $coverage[$slugs[$id]] = intdiv(10000 * (int) ($published[$id] ?? 0), (int) $totals[$id]);
            }
        }

        $glossary = TranslationGlossary::query()->where('locale', $package->locale)
            ->where(fn ($q) => $q->whereNull('game_id')->orWhere('game_id', $package->game_id))->get()
            ->map(fn ($g) => ['term' => $g->term, 'translation' => $g->translation, 'dnt' => $g->do_not_translate])->values()->all();

        $versions = $package->game->versions->keyBy('id');
        $meta = [
            'game_id' => $package->game->slug,
            'package_id' => $package->slug,
            'version' => $version->version,
            'locale' => $package->locale,
            'localization_style' => $package->style->slug,
            'package_type' => $package->is_bundle ? 'bundle' : 'package',
            'categories' => $categories->pluck('slug')->values()->all(),
            'game_versions' => ['min' => $versions[$minId]->version ?? null, 'max' => $versions[$maxId]->version ?? null],
            'platforms' => $package->platforms->pluck('slug')->values()->all(),
            'min_app_version' => $version->min_app_version ?? '1.0.0',
            'coverage_bp' => (object) $coverage,
            'release_notes' => (object) ($version->getTranslations('release_notes') ?: []),
            'glossary' => $glossary,
        ];

        return [$meta, $entries];
    }

    /** Previous built release of the same major that a delta can start from. */
    private function deltaBase(PackageVersion $version): ?PackageVersion
    {
        return PackageVersion::query()->where('package_id', $version->package_id)->where('major', $version->major)
            ->where('build_status', 'released')->whereKeyNot($version->id)->whereNotNull('content_key_encrypted')
            ->orderByDesc('released_at')->orderByDesc('id')->get()->first(fn ($v) => $v->fullFile() !== null);
    }

    /** @return array{code: int, json: array|null, stderr: string} */
    private function run(array $args, array $env): array
    {
        $bin = (string) config('lugha.pkg_binary');
        if (! is_executable($bin)) {
            throw new RuntimeException("lugha-pkg not found at $bin (build it: cargo build --release -p lugha-pkg).");
        }
        $p = new Process([$bin, ...$args], null, $env + ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'], null, 600);
        $p->run();
        $code = (int) $p->getExitCode();
        if (! in_array($code, [0, 2], true)) {
            throw new RuntimeException('lugha-pkg failed: '.trim($p->getErrorOutput() ?: $p->getOutput()));
        }

        return ['code' => $code, 'json' => json_decode($p->getOutput(), true), 'stderr' => $p->getErrorOutput()];
    }
}
