<?php

namespace Database\Seeders;

use App\Enums\GameStatus;
use App\Enums\PackageStatus;
use App\Enums\RightsStatus;
use App\Enums\TranslationStatus;
use App\Enums\UpdatePolicy;
use App\Models\Game;
use App\Models\LocalizationPackage;
use App\Models\LocalizationStyle;
use App\Models\PackageUpgrade;
use App\Models\Platform;
use App\Models\Translation;
use App\Models\TranslationCategory;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local demo data. Games are FICTIONAL placeholders (no real titles or assets).
 * Accounts: admin@lugha.test / player@lugha.test, password "password" — local only.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(['email' => 'admin@lugha.test'], ['name' => 'LUGHA Admin', 'password' => 'password', 'locale' => 'ar']);
        $admin->forceFill(['is_staff' => true, 'email_verified_at' => now()])->save();
        $admin->syncRoles(['super_admin']);

        $player = User::updateOrCreate(['email' => 'player@lugha.test'], ['name' => 'لاعب تجريبي', 'password' => 'password', 'locale' => 'ar']);
        $player->forceFill(['email_verified_at' => now()])->save();

        $cat = TranslationCategory::pluck('id', 'slug');
        $style = LocalizationStyle::where('slug', 'saudi_white_dialect')->value('id');
        $plat = Platform::pluck('id', 'slug');

        $games = [
            ['sandline', 'Sandline', 'خط الرمال', ['pc', 'ps5', 'xbox_series'], true, GameStatus::Published, RightsStatus::Licensed],
            ['iron-tide', 'Iron Tide', 'المدّ الحديدي', ['pc', 'ps5'], true, GameStatus::Published, RightsStatus::Licensed],
            ['hollow-crown', 'Hollow Crown', 'التاج الأجوف', ['pc', 'xbox_series'], true, GameStatus::Published, RightsStatus::Permitted],
            ['last-lantern', 'Last Lantern', 'الفانوس الأخير', ['pc'], false, GameStatus::ComingSoon, RightsStatus::Pending],
        ];

        foreach ($games as $i => [$slug, $en, $ar, $platforms, $featured, $status, $rights]) {
            $game = Game::updateOrCreate(['slug' => $slug], [
                'title' => ['en' => $en, 'ar' => $ar],
                'description' => [
                    'en' => "[Publisher description for {$en} — managed in the admin.]",
                    'ar' => "[وصف {$ar} من الناشر — يُدار من لوحة التحكم.]",
                ],
                'developer' => '[Developer]', 'publisher' => '[Publisher]',
                'status' => $status, 'rights_status' => $rights, 'is_featured' => $featured,
                'published_at' => now()->subDays(30 - $i * 5),
            ]);
            $game->platforms()->sync(collect($platforms)->map(fn ($p) => $plat[$p])->all());
            $game->versions()->firstOrCreate(['version' => '1.00']);
            $game->versions()->firstOrCreate(['version' => '1.06']);

            $tiers = [
                ['dialogue', 'Dialogue Pack', 'باقة الحوارات', 'Understand the story from the characters', 'افهم القصة من أفواه الشخصيات', ['dialogues', 'side_dialogues', 'cutscenes'], 3900, null, UpdatePolicy::VersionLine],
                ['story', 'Story Pack', 'باقة القصة', 'Dialogue plus everything about missions', 'الحوارات + كل ما يخص المهام', ['dialogues', 'side_dialogues', 'cutscenes', 'missions', 'mission_descriptions', 'story_notifications'], 6900, null, UpdatePolicy::VersionLine],
                ['full', 'Full Localization', 'التعريب الكامل', 'Every line of text you see', 'كل نص تراه في اللعبة', ['dialogues', 'side_dialogues', 'cutscenes', 'missions', 'mission_descriptions', 'story_notifications', 'menus', 'settings', 'notifications', 'ui', 'system_messages', 'items', 'tutorials'], 11900, null, UpdatePolicy::VersionLine],
            ];
            $created = [];
            foreach ($tiers as $rank => [$key, $nEn, $nAr, $sEn, $sAr, $cats, $price, $sale, $policy]) {
                $pkg = LocalizationPackage::updateOrCreate(['slug' => "{$slug}-sa-{$key}"], [
                    'game_id' => $game->id, 'name' => ['en' => $nEn, 'ar' => $nAr], 'summary' => ['en' => $sEn, 'ar' => $sAr],
                    'tier_rank' => $rank + 1, 'style_id' => $style, 'update_policy' => $policy, 'sort_order' => $rank,
                    'status' => $rights->allowsSelling() ? PackageStatus::Published : PackageStatus::Draft,
                ]);
                $pkg->categories()->sync(collect($cats)->map(fn ($c) => $cat[$c])->all());
                $pkg->platforms()->sync($game->platforms->pluck('id')->all());
                $pkg->prices()->updateOrCreate(['currency' => 'SAR'], ['base_amount' => $price, 'sale_amount' => $sale]);
                $created[$key] = $pkg;
            }

            $ultimate = LocalizationPackage::updateOrCreate(['slug' => "{$slug}-sa-ultimate"], [
                'game_id' => $game->id, 'name' => ['en' => 'Ultimate Bundle', 'ar' => 'الحزمة الشاملة'],
                'summary' => ['en' => 'Everything, plus future content per the terms', 'ar' => 'كل شيء، وكل تحديث قادم وفق الشروط'],
                'tier_rank' => 4, 'style_id' => $style, 'update_policy' => UpdatePolicy::Lifetime, 'sort_order' => 3,
                'is_bundle' => true, 'status' => $rights->allowsSelling() ? PackageStatus::Published : PackageStatus::Draft,
            ]);
            $ultimate->categories()->sync([$cat['codex']]);
            $ultimate->inclusions()->sync([$created['full']->id]);
            $ultimate->platforms()->sync($game->platforms->pluck('id')->all());
            $ultimate->prices()->updateOrCreate(['currency' => 'SAR'], ['base_amount' => 17900, 'sale_amount' => 14900]);

            // Upgrade paths priced by the admin (illustrative).
            foreach ([['dialogue', 'story', 3000], ['dialogue', 'full', 8000], ['story', 'full', 5000]] as [$from, $to, $amt]) {
                PackageUpgrade::updateOrCreate(
                    ['from_package_id' => $created[$from]->id, 'to_package_id' => $created[$to]->id, 'currency' => 'SAR'],
                    ['amount' => $amt],
                );
            }

            if ($slug === 'sandline') {
                $lines = [
                    ['M03_CUT_014', 'cutscenes', 'We leave at dawn. Nobody talks about tonight.', 'نطلع مع الفجر. وأحد ما يتكلم عن الليلة.', 'Rim', TranslationStatus::InReview],
                    ['M03_CUT_015', 'cutscenes', 'And if the Warden asks?', 'وإذا سأل الـWarden؟', 'Salem', TranslationStatus::Rejected],
                    ['M03_OBJ_002', 'missions', 'Catch up with the caravan before sunset', 'الحق بالقافلة قبل الغروب', null, TranslationStatus::Approved],
                    ['SD_TRADER_07', 'side_dialogues', 'Water costs more than gold out here.', 'الماء هنا أغلى من الذهب.', 'Trader', TranslationStatus::Published],
                    ['UI_MENU_CONT', 'menus', 'Continue', 'متابعة', null, TranslationStatus::Published],
                    ['UI_SET_SUBS', 'settings', 'Subtitle size', 'حجم الترجمة', null, TranslationStatus::Draft],
                    ['TUT_SPRINT', 'tutorials', 'Hold [Shift] to sprint', null, null, TranslationStatus::Draft],
                ];
                foreach ($lines as $n => [$key, $c, $src, $tgt, $speaker, $st]) {
                    Translation::updateOrCreate(['game_id' => $game->id, 'string_key' => $key], [
                        'category_id' => $cat[$c], 'style_id' => $style, 'source_text' => $src, 'target_text' => $tgt,
                        'speaker' => $speaker, 'status' => $st, 'sequence_no' => $n + 1, 'scene_ref' => 'M03',
                    ]);
                }
            }
        }
    }
}
