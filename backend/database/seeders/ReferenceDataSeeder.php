<?php

namespace Database\Seeders;

use App\Models\LocalizationStyle;
use App\Models\Platform;
use App\Models\TranslationCategory;
use Illuminate\Database\Seeder;

class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $platforms = [
            ['pc', 'PC', 'PC'], ['ps5', 'PlayStation 5', 'PlayStation 5'], ['xbox_series', 'Xbox Series X|S', 'Xbox Series X|S'],
            ['switch2', 'Nintendo Switch 2', 'Nintendo Switch 2'],
        ];
        foreach ($platforms as $i => [$slug, $en, $ar]) {
            Platform::updateOrCreate(['slug' => $slug], ['name' => ['en' => $en, 'ar' => $ar], 'sort_order' => $i]);
        }

        $categories = [
            ['dialogues', 'Main story dialogue', 'حوارات القصة الرئيسية'],
            ['side_dialogues', 'Side character dialogue', 'حوارات الشخصيات الجانبية'],
            ['cutscenes', 'Cutscene subtitles', 'ترجمة المشاهد السينمائية'],
            ['missions', 'Mission objectives', 'أهداف المهام'],
            ['mission_descriptions', 'Mission descriptions', 'وصف المهام'],
            ['story_notifications', 'Story notifications', 'إشعارات القصة'],
            ['menus', 'Menus', 'القوائم'],
            ['settings', 'Settings', 'الإعدادات'],
            ['notifications', 'Notifications', 'الإشعارات'],
            ['ui', 'HUD & UI', 'واجهة اللعب'],
            ['system_messages', 'System messages', 'رسائل النظام'],
            ['items', 'Item descriptions', 'وصف الأدوات'],
            ['tutorials', 'Tutorials', 'الدروس التعليمية'],
            ['codex', 'Codex & lore', 'الموسوعة'],
        ];
        foreach ($categories as $i => [$slug, $en, $ar]) {
            TranslationCategory::updateOrCreate(['slug' => $slug], ['name' => ['en' => $en, 'ar' => $ar], 'sort_order' => $i]);
        }

        foreach ([
            ['msa', 'Modern Standard Arabic', 'العربية الفصحى'],
            ['saudi_white_dialect', 'Saudi white dialect', 'لهجة سعودية بيضاء'],
            ['gulf', 'Gulf Arabic', 'لهجة خليجية'],
        ] as [$slug, $en, $ar]) {
            LocalizationStyle::updateOrCreate(['slug' => $slug], ['name' => ['en' => $en, 'ar' => $ar]]);
        }
    }
}
