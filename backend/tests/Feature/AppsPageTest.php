<?php

namespace Tests\Feature;

use Tests\TestCase;

class AppsPageTest extends TestCase
{
    public function test_the_public_download_page_lists_every_app(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $this->get("/{$locale}/apps")
                ->assertOk()
                ->assertSee('mac.dmg')
                ->assertSee('windows.exe')
                ->assertSee('tv.apk')
                ->assertSee('2948953');
        }
    }
}
