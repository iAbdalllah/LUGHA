<?php

namespace Tests\Concerns;

use App\Enums\GameStatus;
use App\Enums\PackageStatus;
use App\Enums\RightsStatus;
use App\Enums\UpdatePolicy;
use App\Models\Game;
use App\Models\LocalizationPackage;
use App\Models\LocalizationStyle;
use App\Models\PackageUpgrade;
use App\Models\TranslationCategory;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RoleSeeder;

trait BuildsCatalog
{
    protected function seedReference(): void
    {
        $this->seed([RoleSeeder::class, ReferenceDataSeeder::class]);
    }

    protected function game(array $attrs = []): Game
    {
        return Game::create($attrs + [
            'slug' => 'game-'.uniqid(), 'title' => ['ar' => 'لعبة', 'en' => 'Game'],
            'status' => GameStatus::Published, 'rights_status' => RightsStatus::Licensed, 'published_at' => now(),
        ]);
    }

    /** @param string[] $categories */
    protected function package(Game $game, int $price, array $categories = ['dialogues'], array $attrs = []): LocalizationPackage
    {
        $pkg = LocalizationPackage::create($attrs + [
            'game_id' => $game->id, 'slug' => 'pkg-'.uniqid(), 'name' => ['ar' => 'باقة', 'en' => 'Pack'],
            'style_id' => LocalizationStyle::where('slug', 'saudi_white_dialect')->value('id'),
            'update_policy' => UpdatePolicy::VersionLine, 'status' => PackageStatus::Published,
        ]);
        $pkg->categories()->sync(TranslationCategory::whereIn('slug', $categories)->pluck('id'));
        $pkg->prices()->create(['currency' => 'SAR', 'base_amount' => $price]);

        return $pkg->fresh();
    }

    protected function upgrade(LocalizationPackage $from, LocalizationPackage $to, int $amount): void
    {
        PackageUpgrade::create(['from_package_id' => $from->id, 'to_package_id' => $to->id, 'currency' => 'SAR', 'amount' => $amount]);
    }

    protected function customer(array $attrs = []): User
    {
        $u = User::create($attrs + ['name' => 'Player', 'email' => uniqid().'@example.test', 'password' => 'password-123']);
        $u->forceFill(['email_verified_at' => now()])->save();

        return $u;
    }

    protected function staff(string $role = 'super_admin'): User
    {
        $u = $this->customer();
        $u->forceFill(['is_staff' => true])->save();
        $u->assignRole($role);

        return $u;
    }
}
